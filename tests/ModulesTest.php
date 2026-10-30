<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Tests;

use BlueEvent\Event\Base\EventDispatcher;
use Bluetree\Api\Core\Modules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleLog\Log;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the example module shipped in modules/Hello.
 */
#[CoversClass(Modules::class)]
class ModulesTest extends TestCase
{
    private function loadConfig(): array
    {
        return (new Modules(
            $this->createStub(Log::class),
            null,
            null,
            $this->createStub(EventDispatcher::class),
            ['main' => ['debug' => false]],
            $this->createStub(Request::class),
        ))->loadConfig();
    }

    public function testModuleMainConfigLandsUnderItsName(): void
    {
        $config = $this->loadConfig();

        self::assertSame([], $config['modules']['Hello']['required_env'] ?? null);
    }

    /**
     * Route configs are merged into one flat list, so the owning module is
     * what authorisation has left to go on.
     */
    public function testRouteIsStampedWithItsModule(): void
    {
        $route = $this->loadConfig()['routes']['/hello'] ?? [];

        self::assertSame('Hello', $route['module'] ?? null);
    }

    public function testRouteKeepsWhatItDeclared(): void
    {
        $route = $this->loadConfig()['routes']['/hello'] ?? [];

        self::assertSame(['GET'], $route['method'] ?? null);
        self::assertSame('Bluetree\Mod\Hello\Controller\HelloController', $route['controller'] ?? null);
        self::assertSame('index', $route['action'] ?? null);
        self::assertFalse($route['restricted'] ?? null);
    }
}
