<?php

declare(strict_types=1);

// The real ApiCore::handleRequest() and the real responses, served by `php -S`,
// because render() ends the process and only a client can see what it sent. All
// that is replaced is the constructor, which would open Redis.

require_once \dirname(__DIR__, 2) . '/vendor/autoload.php';

use BlueCache\Cache;
use BlueCache\Storage\File;
use BlueEvent\Event\Base\EventDispatcher;
use BlueRegister\Register;
use Bluetree\Api\Core\ApiCore;
use Bluetree\Api\Core\Controller\BaseController;
use Bluetree\Api\Core\Helpers\Json;
use Bluetree\Api\Core\Response\BaseResponse;
use Bluetree\Api\Core\Response\SuccessResponse;
use Bluetree\Mod\Hello\HelloAuth;
use SimpleLog\Log;

final class FixtureController extends BaseController
{
    public function ok(): void
    {
        $response = new SuccessResponse();
        $response->genericResponse(200, SuccessResponse::STATUS_SUCCESS, 'ok', Json::payload($this->request));
        $response->render();
    }

    public function fatal(): void
    {
        \ini_set('memory_limit', '64M');
        \str_repeat('x', 1024 * 1024 * 1024);
    }

    public function boom(): void
    {
        $this->openDatabase('S3cretPass!');
    }

    private function openDatabase(string $password): never
    {
        throw new \RuntimeException('database is down');
    }
}

final class FixtureCore extends ApiCore
{
    public function __construct()
    {
        $route = static fn (array $method, string $action, array $extra = []): array => [
            'method' => $method,
            'controller' => FixtureController::class,
            'action' => $action,
        ] + $extra;

        $open = ['restricted' => false];

        $this->register = new Register([]);
        $this->captureWarnings();
        $this->event = new EventDispatcher();
        $this->config = [
            'routes' => [
                '/write' => $route(['POST'], 'ok', $open),
                '/read' => $route(['GET'], 'ok', $open),
                '/no-flag' => $route(['GET'], 'ok'),
                '/fatal' => $route(['GET'], 'fatal', $open),
                '/boom' => $route(['GET'], 'boom', $open),
            ],
            'methods' => Json::readFile(self::configDir() . 'routes.json')['methods'],
        ];
        $this->applyEnvironmentOverrides();

        BaseResponse::setAllowedOrigins(['https://bluetree.pl']);

        $this->logger = new Log([
            'log_path' => \getenv('API_CORE_TEST_DIR'),
            'level' => 'notice',
            'storage' => 'SimpleLog\\Storage\\File',
            'message' => 'SimpleLog\\Message\\DefaultMessage',
        ]);
        $this->cache = new Cache(['storage_class' => File::class, 'cache_path' => \getenv('API_CORE_TEST_DIR')]);
        $this->request = $this->createRequest();
        $this->auth = new HelloAuth($this->logger, $this->cache, $this->register, $this->event, [], $this->request);
    }
}

(new FixtureCore())->handleRequest();
