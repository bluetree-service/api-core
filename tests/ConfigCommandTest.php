<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Tests;

use BlueConsole\Style;
use Bluetree\Api\Core\Console\Commands\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(Config::class)]
class ConfigCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        \putenv('DB_PASSWORD');
        \putenv('DB_USER');
    }

    public function testEnvOptionSaysAVariableIsSetWithoutPrintingIt(): void
    {
        \putenv('DB_PASSWORD=S3cretPass!');
        \putenv('DB_USER');

        // Only the connection to Redis is left out; the option is what is tested.
        $command = new class extends Config {
            protected function register(): void
            {
                $this->blueStyle = new Style($this->input, $this->output, new FormatterHelper());
            }

            protected function getRedisConfig(): bool
            {
                return true;
            }
        };

        $tester = new CommandTester($command);
        $tester->execute(['--env' => true]);
        $display = $tester->getDisplay();

        self::assertStringNotContainsString('S3cretPass', $display);
        self::assertStringContainsString('DB_PASSWORD', $display);
        self::assertStringContainsString("'DB_USER' is not set", $display);
    }
}
