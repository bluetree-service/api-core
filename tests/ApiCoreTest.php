<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Tests;

use Bluetree\Api\Core\ApiCore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiCore::class)]
class ApiCoreTest extends TestCase
{
    /**
     * In this checkout the package is the root project, so the paths land on
     * its own example config and modules - the same way they land on the
     * application's once it is installed under vendor/.
     */
    public function testPathsPointAtTheRootProjectNotAtTheClassFile(): void
    {
        $root = \dirname(__DIR__);

        self::assertSame($root, \realpath(ApiCore::projectDir()));
        self::assertSame("$root/src/config", \realpath(ApiCore::configDir()));
        self::assertSame("$root/modules", \realpath(ApiCore::modulesDir()));
        self::assertFileExists(ApiCore::configDir() . 'main.json');
    }

    #[DataProvider('flags')]
    public function testEnvFlag(?string $value, bool $expected): void
    {
        $value === null ? \putenv('API_CORE_TEST_FLAG') : \putenv("API_CORE_TEST_FLAG=$value");

        self::assertSame($expected, ApiCore::envFlag('API_CORE_TEST_FLAG'));
    }

    public static function flags(): array
    {
        return [
            'unset' => [null, false],
            'empty' => ['', false],
            'one' => ['1', true],
            'true in capitals with spaces' => [' TRUE ', true],
            'yes' => ['yes', true],
            'on' => ['on', true],
            'zero' => ['0', false],
            'false' => ['false', false],
            'anything else' => ['enabled', false],
        ];
    }

    protected function tearDown(): void
    {
        \putenv('API_CORE_TEST_FLAG');
    }
}
