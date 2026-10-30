<?php

declare(strict_types=1);

namespace Bluetree\Api\Core;

use BlueCache\CacheException;
use Composer\InstalledVersions;
use BlueRegister\Register;
use BlueCache\Cache;
use Bluetree\Api\Core\Helpers\{
    Json,
    Auth,
};
use Bluetree\Api\Core\Response\{BaseResponse, ErrorResponse};
use BlueCache\Storage\Redis;
use BlueCache\CacheItem;
use SimpleLog\Log;
use BlueEvent\Event\Base\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

class ApiCore
{
    public const string CACHE_CONFIG_NAME = 'config';
    public const string BASE_CORE_DIR = __DIR__ . '/';
    public const array SUPPORTED_OTHER_CONFIGS = [
        'routes',
        'modules',
        'connections',
        'events'
    ];
    public const array REQUIRED_ENV_VARIABLES = [
        'DB_PASSWORD',
        'DB_USER',
    ];

    protected array $config = [];

    /**
     * Root of the application that installed this package, not of the package.
     * The core lives in vendor/, so anything relative to __DIR__ would point
     * inside vendor instead of at the application's config and modules.
     */
    public static function projectDir(): string
    {
        return \rtrim(InstalledVersions::getRootPackage()['install_path'], '/') . '/';
    }

    public static function configDir(): string
    {
        return self::projectDir() . 'src/config/';
    }

    public static function modulesDir(): string
    {
        return self::projectDir() . 'modules/';
    }

    protected Register $register;
    protected Cache $cache;
    protected ?Log $logger = null;
    protected Modules $modules;
    protected EventDispatcher $event;
    protected Request $request;
    protected Auth $auth;

    /**
     * ApiCore constructor.
     * @throws \JsonException
     */
    public function __construct()
    {
        try {
            $this->event = new EventDispatcher();
            $this->register = new Register([
                'events' => true,
                'event_object' => $this->event,
            ]);
            $this->captureWarnings();
            $this->loadBaseConfig();

            $this->cache = $this->register->factory(
                Cache::class,
                [[
                    'storage_class' => Redis::class,
                    'storage_servers' => [
                        $this->config['main']['cache']['host'],
                        $this->config['main']['cache']['port'],
                        $this->config['main']['cache']['database'] ?? 2,
                    ]
                ]]
            );

            $this->logger = $this->register->factory(
                Log::class,
                [$this->logConfig()]
            );

            $this->request = $this->register->factory(
                Request::class,
                [
                    $_GET,
                    $_POST,
                    [],
                    $_COOKIE,
                    $_FILES,
                    $_SERVER
                ]
            );

            $this->auth = $this->register->factory(
                $this->config['main']['auth']['auth_class'],
                [
                    'logger' => $this->logger,
                    'cache' => $this->cache,
                    'register' => $this->register,
                    'event' => $this->event,
                    'config' => $this->config,
                    'request' => $this->request,
                ]
            );

            $this->modules = $this->register->factory(
                Modules::class,
                [
                    'logger' => $this->logger,
                    'cache' => $this->cache,
                    'register' => $this->register,
                    'event' => $this->event,
                    'config' => $this->config,
                    'request' => $this->request,
                ]
            );
        } catch (\Throwable $exception) {
            // Same rule as handleError(), applied before there is a logger or a
            // resolved config to ask: an unreachable cache names its host, and
            // that is not for whoever sent the request - the healthcheck
            // included. The container log gets the whole thing under the id.
            $errorId = \bin2hex(\random_bytes(4));
            $message = ['error_id' => $errorId];

            \error_log("[$errorId] {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}");

            if (self::envFlag('APP_DEBUG')) {
                $message['error'] = $exception->getMessage();
                $message['file'] = $exception->getFile();
                $message['line'] = $exception->getLine();
                $message['trace'] = $exception->getTraceAsString();
            }

            $response = new ErrorResponse();
            $response->responseInternalError(
                'Failed initialize API',
                $message
            );
            $response->render();
        }
    }

    /**
     * @throws \JsonException
     */
    public function run(): void
    {
        try {
            $conf = $this->cache->getItem('config');
            $cached = (bool) $conf?->isHit();

            if ($cached) {
                $this->config = $conf?->get();
            } else {
                $this->loadConfigurations();
                $this->saveConfigToCache();
            }

            $this->applyEnvironmentOverrides();

            // Before the first log line, because a failure to write one must
            // still come back as a CORS-bearing error the browser can show,
            // rather than as a connection the front end cannot explain.
            BaseResponse::setAllowedOrigins($this->config['main']['cors']['allowed_origins'] ?? []);

            $this->debugMessage($cached ? 'Config loaded from cache' : 'New config loaded');

            $this->modules->setFinalConfig($this->config);
            $this->auth->setFinalConfig($this->config);
            $this->modules->runModules();
        } catch (\Throwable $exception) {
            $this->handleError($exception, 'Failed to run API');
            exit;
        }
    }

    public function loadConfigurations(): void
    {
        $modulesConfig = $this->loadModules();
        $allCoreConfig = $this->loadAllConfigs();
        $this->config = \array_replace_recursive($this->config, $modulesConfig, $allCoreConfig);
    }

    public function returnConfigs(): array
    {
        return $this->config;
    }

    /**
     * Debugging detail is off unless the environment asks for it.
     *
     * The configuration files are committed and every environment reads the
     * same ones, so a flag left true there reaches production as well - which
     * is how stack traces ended up in production responses. Applied after the
     * configuration is resolved, including when it came from the cache, so the
     * flag is never baked into a cached configuration.
     */
    protected function applyEnvironmentOverrides(): void
    {
        $debug = self::envFlag('APP_DEBUG');

        $this->config['main']['debug'] = $debug;
        $this->config['main']['show_exceptions_messages'] = $debug;
    }

    public static function envFlag(string $name): bool
    {
        $value = \getenv($name);

        if ($value === false) {
            return false;
        }

        return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    protected function debugMessage($message): void
    {
        if (($this->config['main']['debug'] ?? false) !== true) {
            return;
        }

        // An unwritable log directory is a problem with the server, not with
        // the request. It gets reported by `app:health`; it does not get to
        // turn every response into a 500.
        try {
            $this->logger->debug($message);
        } catch (\Throwable) {
        }
    }

    /**
     * @throws \JsonException
     */
    public function handleRequest(): void
    {
        try {
            $path = \parse_url($this->request->getRequestUri(), PHP_URL_PATH);
            $matchedRoute = null;

            foreach ($this->config['routes'] as $route => $handler) {
                $method = $this->config['routes'][$path]['method'] ?? null;

                if ($path === $route && \in_array($this->request->getMethod(), $method, true)) {
                    $this->debugMessage('Matched route: ' . $route);
                    $matchedRoute = $handler;
                    break;
                }
                $this->debugMessage('Route not matched: ' . $route);
            }

            if (!$matchedRoute) {
                $matchedRoute = $this->handleMethods();
            }

            if ($matchedRoute) {
                $this->auth($matchedRoute);

                $action = $matchedRoute['action'];
                $controller = $this->register->factory(
                    $matchedRoute['controller'],
                    [
                        'logger' => $this->logger,
                        'cache' => $this->cache,
                        'register' => $this->register,
                        'event' => $this->event,
                        'config' => $this->config,
                        'request' => $this->request,
                    ]
                );
                $controller->$action();
                return;
            }

            $response = new ErrorResponse();
            $response->responseNotFound('Route not found');
            $response->render();
        } catch (\Throwable $exception) {
            $this->handleError($exception, 'Failed to handle Request');
        }
    }

    protected function auth(array $matchedRoute): void
    {
        $restricted = $matchedRoute['restricted'] ?? false;

        if ($restricted) {
            $auth = $this->auth->checkAuth($matchedRoute);

            if (!$auth) {
                $response = new ErrorResponse();

                // A session that stands but does not reach this module is not a
                // session problem. Answering 401 tells the front end the session
                // is gone, it sends the user to log in, the login page finds the
                // session perfectly good and sends them back here — which is the
                // flicker between a page and the login form.
                if ($this->auth->session() === null) {
                    $response->responseUnauthorized('Unauthorized');
                } else {
                    $response->responseForbidden('Brak dostępu do tego modułu.');
                }

                $response->render();
            }
        }
    }

    /**
     * @return array|null
     */
    public function handleMethods(): ?array
    {
        $method = $this->request->getMethod();
        $handler = $this->config['methods'][$method] ?? null;

        if ($handler) {
            return $handler;
        }

        return null;
    }

    /**
     * @throws \JsonException
     */
    protected function loadModules(): array
    {
        return $this->modules->loadConfig();
    }

    /**
     * @param \Throwable $exception
     * @param string $responseMessage
     * @throws \JsonException
     */
    protected function handleError(\Throwable $exception, string $responseMessage): void
    {
        $response = new ErrorResponse();

        // Names the incident without describing it. The response carries the
        // id and nothing else; the log carries the id and everything else, so
        // a production failure stays diagnosable by grepping for it.
        $errorId = \bin2hex(\random_bytes(4));
        $message = ['error_id' => $errorId];

        if ($this->config['main']['show_exceptions_messages'] ?? false) {
            $message['error'] = $exception->getMessage();

            if (($this->config['main']['debug'] ?? false) === true) {
                $message['file'] = $exception->getFile();
                $message['line'] = $exception->getLine();
                $message['trace'] = $exception->getTraceAsString();
            }
        }

        try {
            $this?->logger?->critical(
                "[$errorId] " . $exception->getMessage() . "; File: {file}; Line: {line}; Trace:\n{trace}",
                [
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]
            );
        } catch (\Throwable) {
            // Losing the log entry must not replace the error being reported.
            \error_log("[$errorId] {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}");
        }

        // The failure may have landed before run() got this far.
        BaseResponse::setAllowedOrigins($this->config['main']['cors']['allowed_origins'] ?? []);

        $response->responseInternalError(
            $responseMessage,
            $message
        );
        $response->render();
    }

    /**
     * @throws CacheException
     */
    protected function saveConfigToCache(): void
    {
        $item = new CacheItem(self::CACHE_CONFIG_NAME);
        $item->set($this->config);
        $item->expiresAfter($this->config['main']['cache']['expires']);
        $this->cache->save($item);
    }

    /**
     * @throws \JsonException
     */
    /**
     * Log settings with the path made absolute.
     *
     * A relative path lands wherever the process happens to be: the built-in
     * server ran from the project root, php-fpm runs from the directory of the
     * script it is given. Resolving it here keeps the log in one place whatever
     * starts the code, the console included.
     */
    protected function logConfig(): array
    {
        $log = $this->config['main']['log'];
        $path = $log['log_path'] ?? './log';

        if (!\str_starts_with($path, '/')) {
            $log['log_path'] = \rtrim(self::projectDir(), '/') . '/' . \ltrim($path, './');
        }

        return $log;
    }

    protected function loadBaseConfig(): void
    {
        $jsonConfig = Json::readFile(self::configDir() . 'main.json');

        $this->config = \array_replace_recursive($this->config, $jsonConfig);
    }

    protected function loadAllConfigs(): array
    {
        $config = [];

        foreach (self::SUPPORTED_OTHER_CONFIGS as $configName) {
            if (\file_exists(self::configDir() . "$configName.json")) {
                $jsonConfig = Json::readFile(self::configDir() . "$configName.json");
                $config = \array_replace_recursive($config, $jsonConfig);
            }
        }

        return $config;
    }

    protected function captureWarnings(): void
    {
        \set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        \register_shutdown_function(function () {
            $error = \error_get_last();

            if ($error !== null) {
                $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

                if (\in_array($error['type'], $fatalTypes, true)) {
                    \http_response_code(500);

                    echo \json_encode([
                        'status' => 500,
                        'message' => 'Fatal error',
                        'details' => [
                            'type' => $error['type'],
                            'message' => $error['message'],
                            'file' => $error['file'],
                            'line' => $error['line'],
                        ],
                    ], JSON_THROW_ON_ERROR);
                }
            }
        });
    }
}
