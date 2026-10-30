<?php

declare(strict_types=1);

namespace Bluetree\Api\Core;

use BlueCache\Cache as BlueCache;
use BlueCache\Storage\Redis;
use BlueRegister\Register;
use Bluetree\Api\Core\Helpers\Db;
use Bluetree\Api\Core\Response\BaseResponse;
use Bluetree\Api\Core\Response\ErrorResponse;
use Bluetree\Api\Core\Response\SuccessResponse;

/**
 * Answers whether this installation can reach what it depends on.
 *
 * Written to be run after a deploy: everything it checks has, at some point,
 * been the reason something did not work — an environment variable that never
 * made it into the container, a database that was never migrated, a cache on
 * the wrong host.
 *
 * Only collects answers; `app:health` prints them in full and respond()
 * reduces them to one status per check, since hosts and exception messages are
 * not something to hand to whoever asks over HTTP.
 */
class Health
{
    public const string OK = 'ok';
    public const string WARNING = 'warning';
    public const string FAIL = 'fail';

    /**
     * The user php-fpm serves requests as; see the www.conf of the base image.
     */
    protected const string WEB_USER = 'www-data';

    /**
     * Distinct from a failure: the question could not be put at all.
     */
    protected const string PROBE_UNAVAILABLE = 'probe-unavailable';

    /** Drivers the data layer knows how to open. */
    protected const array SUPPORTED_DRIVERS = ['maria_db', 'mysql'];

    /**
     * @var list<array{check: string, label: string, status: string, message: string}>
     */
    protected array $results = [];
    protected string $check = '';
    protected string $label = '';

    public function __construct(
        protected array $config,
        protected Register $register = new Register([]),
    ) {
    }

    /**
     * @return list<array{check: string, label: string, status: string, message: string}>
     */
    public function run(): array
    {
        $this->results = [];

        $this->checkEnvironment();
        $this->checkCache();
        $this->checkDatabases();
        $this->checkLogDirectory();
        $this->checkModules();

        return $this->results;
    }

    /**
     * The whole HTTP healthcheck, so an application's pub/healthcheck.php is
     * the autoloader and this call.
     *
     * @throws \JsonException
     */
    public static function respond(): void
    {
        // A cache that does not answer stops ApiCore itself, which then answers
        // 500 on its own; everything past this point has the configuration.
        $core = new ApiCore();
        $core->run();

        self::response((new self($core->returnConfigs()))->run())->render();
    }

    /**
     * 200 or 503 with one status per check and nothing else: hosts, paths and
     * exception messages stay with `app:health`.
     */
    public static function response(array $results): BaseResponse
    {
        if (self::failed($results)) {
            $response = new ErrorResponse();
            $response->genericResponse(
                503,
                ErrorResponse::STATUS_FAILED,
                'Something this installation depends on is not reachable.',
                self::summary($results)
            );

            return $response;
        }

        $response = new SuccessResponse();
        $response->genericResponse(200, SuccessResponse::STATUS_SUCCESS, 'Everything answered.', self::summary($results));

        return $response;
    }

    public static function failed(array $results): bool
    {
        return \in_array(self::FAIL, \array_column($results, 'status'), true);
    }

    /**
     * The worst status of every check, without the messages.
     *
     * @return array<string, string>
     */
    public static function summary(array $results): array
    {
        $rank = [self::OK => 0, self::WARNING => 1, self::FAIL => 2];
        $summary = [];

        foreach ($results as $result) {
            $current = $summary[$result['check']] ?? self::OK;

            if ($rank[$result['status']] >= $rank[$current]) {
                $summary[$result['check']] = $result['status'];
            }
        }

        return $summary;
    }

    /**
     * The variables the container has to carry. Empty ones are the usual reason
     * a freshly deployed api cannot open anything.
     */
    protected function checkEnvironment(): void
    {
        $this->section('environment', 'Environment');

        $required = ApiCore::REQUIRED_ENV_VARIABLES;

        // Variables a module cannot work without, declared in its own main.json
        // as "required_env" and checked only when the module is loaded. An unset
        // token usually deploys cleanly and surfaces much later, far from the cause.
        foreach ($this->config['modules'] ?? [] as $module) {
            $required = [...$required, ...($module['required_env'] ?? [])];
        }

        foreach ($required as $name) {
            $value = \getenv($name);

            if ($value === false || $value === '') {
                $this->fail("$name is not set");
                continue;
            }

            $this->ok("$name is set");
        }
    }

    protected function checkCache(): void
    {
        $cache = $this->config['main']['cache'] ?? [];
        $where = ($cache['host'] ?? '?') . ':' . ($cache['port'] ?? '?');

        $this->section('cache', "Cache ($where)");

        try {
            /** @var BlueCache $storage */
            $storage = $this->register->factory(
                BlueCache::class,
                [[
                    'storage_class' => Redis::class,
                    'storage_servers' => [
                        $cache['host'] ?? null,
                        $cache['port'] ?? null,
                        $cache['database'] ?? 2,
                    ],
                ]]
            );

            // A read proves the connection; the config block is the one thing
            // certain to be there once the api has run.
            $storage->getItem('config');

            $this->ok('redis answered');
        } catch (\Throwable $exception) {
            $this->fail('redis did not answer: ' . $exception->getMessage());
        }
    }

    /**
     * Every connection the merged configuration names, module ones included.
     */
    protected function checkDatabases(): void
    {
        $connections = $this->config['connections']['database'] ?? [];

        if ($connections === []) {
            $this->section('databases', 'Databases');
            $this->fail('none are configured');

            return;
        }

        foreach ($connections as $name => $connection) {
            $driver = $connection['driver'] ?? 'maria_db';
            $where = ($connection['host'] ?? '?') . ':' . ($connection['port'] ?? '?')
                . '/' . ($connection['database'] ?? '?');

            $this->section("database:$name", "Database $name ($where)");

            if (!\in_array($driver, self::SUPPORTED_DRIVERS, true)) {
                // Not a failure: the data layer cannot open it, so nothing in
                // the application is using it either.
                $this->warning("skipped, driver '$driver' is not one this api opens");
                continue;
            }

            $this->checkConnection($connection);
        }
    }

    protected function checkConnection(array $connection): void
    {
        try {
            $db = new Db();
            $db->connect(
                \getenv('DB_USER') ?: '',
                \getenv('DB_PASSWORD') ?: '',
                $connection['host'] ?? '',
                (int)($connection['port'] ?? 3306),
                $connection['database'] ?? ''
            );

            $db->queryOne('SELECT 1 AS ok');
            $this->ok('connected');

            $tables = $db->queryOne(
                'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE()'
            );
            $total = (int)($tables['total'] ?? 0);

            if ($total === 0) {
                // What an unmigrated database looks like.
                $this->fail('no tables — has it been migrated?');

                return;
            }

            $this->ok("$total tables");
        } catch (\Throwable $exception) {
            $this->fail($exception->getMessage());
        }
    }

    /**
     * The path moved once already when the runtime changed, and a log nobody
     * can write is only noticed when something else has gone wrong.
     */
    protected function checkLogDirectory(): void
    {
        $path = $this->config['main']['log']['log_path'] ?? './log';

        if (!\str_starts_with($path, '/')) {
            $path = \rtrim(ApiCore::projectDir(), '/') . '/' . \ltrim($path, './');
        }

        $path = \realpath($path) ?: $path;

        $this->section('log', 'Log directory');

        if (!\is_dir($path)) {
            $this->fail("$path does not exist");

            return;
        }

        // is_writable() answers for whoever runs the command, and in a container
        // that is usually root, who can write anywhere. php-fpm serves requests
        // as www-data, so that is the only answer worth having: this check
        // passed as root while every request was dying on a log write.
        //
        // Asked by attempting the write rather than by reading permission bits,
        // because a bind-mounted directory reports ownership that has nothing
        // to do with whether the write succeeds.
        $failure = $this->probeAsWebUser($path);

        if ($failure === self::PROBE_UNAVAILABLE) {
            $this->warning('cannot test as ' . self::WEB_USER . ', answering for the current user instead');

            if (!\is_writable($path)) {
                $this->fail("$path is not writable");

                return;
            }

            $this->ok("$path is writable");

            return;
        }

        if ($failure !== null) {
            $this->fail($failure);

            return;
        }

        $this->ok("$path is writable by " . self::WEB_USER);
    }

    /**
     * Writes a probe file into the directory as the web user and checks that
     * every log file already there can be appended to - a writable directory
     * holding a root-owned debug.log fails just the same, and only on the line
     * that tries to append to it.
     *
     * @return string|null the failure, null when the write worked, or
     *                     self::PROBE_UNAVAILABLE when it could not be asked
     */
    protected function probeAsWebUser(string $path): ?string
    {
        if (!\function_exists('exec')
            || !\function_exists('posix_getpwnam')
            || \posix_getpwnam(self::WEB_USER) === false
        ) {
            return self::PROBE_UNAVAILABLE;
        }

        $dir = \escapeshellarg($path);
        $script = "probe=$dir/.health-write-probe; : > \$probe || exit 3; rm -f \$probe; "
            . "for f in $dir/*.log; do [ -e \"\$f\" ] || continue; "
            . "[ -w \"\$f\" ] || { echo \"\$f\"; exit 4; }; done";

        $command = \posix_geteuid() === (int) \posix_getpwnam(self::WEB_USER)['uid']
            ? "sh -c " . \escapeshellarg($script)
            : \sprintf(
                'su -s /bin/sh %s -c %s',
                \escapeshellarg(self::WEB_USER),
                \escapeshellarg($script)
            );

        $output = [];
        $code = 0;
        @\exec($command . ' 2>&1', $output, $code);

        return match ($code) {
            0 => null,
            3 => "$path is not writable by " . self::WEB_USER . ' ' . $this->describePermissions($path),
            4 => 'not writable by ' . self::WEB_USER . ': ' . \implode(', ', \array_map(
                fn (string $file): string => \basename($file) . ' ' . $this->describePermissions($file),
                $output
            )),
            default => self::PROBE_UNAVAILABLE,
        };
    }

    protected function describePermissions(string $path): string
    {
        $mode = \substr(\sprintf('%o', @\fileperms($path) ?: 0), -4);

        return \sprintf('(owner %d:%d, mode %s)', \fileowner($path), \filegroup($path), $mode);
    }

    /**
     * A module whose configuration failed to load leaves no trace at runtime
     * beyond its routes quietly not being there.
     */
    protected function checkModules(): void
    {
        $modules = \array_keys($this->config['modules'] ?? []);
        $routes = \count($this->config['routes'] ?? []);

        $this->section('modules', 'Modules');

        if ($modules === []) {
            $this->fail('none were loaded');

            return;
        }

        $this->ok(\implode(', ', $modules));
        $this->ok("$routes routes");
    }

    protected function section(string $check, string $label): void
    {
        $this->check = $check;
        $this->label = $label;
    }

    protected function ok(string $message): void
    {
        $this->record(self::OK, $message);
    }

    protected function warning(string $message): void
    {
        $this->record(self::WARNING, $message);
    }

    protected function fail(string $message): void
    {
        $this->record(self::FAIL, $message);
    }

    protected function record(string $status, string $message): void
    {
        $this->results[] = [
            'check' => $this->check,
            'label' => $this->label,
            'status' => $status,
            'message' => $message,
        ];
    }
}
