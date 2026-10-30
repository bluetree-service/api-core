<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Tests;

use Bluetree\Api\Core\Console\Commands;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scripts are run as separate processes, the way php-fpm and a shell run
 * them, so a wrong autoloader path fails here rather than after a deploy.
 */
#[CoversClass(Commands::class)]
class EntryPointsTest extends TestCase
{
    /**
     * @param bool $withStderr false for the pub scripts: under php-fpm stderr
     *                         is the container log, not part of the response
     */
    private function runScript(string $script, string $arguments = '', bool $withStderr = true): string
    {
        $output = [];
        \exec(
            'APP_DEBUG= ' . \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg(\dirname(__DIR__) . "/$script") . " $arguments " . ($withStderr ? '2>&1' : '2>/dev/null'),
            $output
        );

        return \implode("\n", $output);
    }

    public function testConsoleFindsCoreAndModuleCommands(): void
    {
        $output = $this->runScript('bin/console', 'list --raw');

        foreach (['app:health', 'app:config', 'app:cache', 'app:hello'] as $command) {
            self::assertMatchesRegularExpression("/\\s$command\\s/", $output, "$command is missing:\n$output");
        }
    }

    public function testModuleCommandRuns(): void
    {
        self::assertStringContainsString('Hello World', $this->runScript('bin/console', 'app:hello'));
    }

    /**
     * Whatever the cache and database here can or cannot answer, the reply is
     * the API's envelope and not a PHP error page.
     */
    #[DataProvider('pubScripts')]
    public function testPubScriptsAnswerWithTheEnvelope(string $script): void
    {
        $output = $this->runScript($script, withStderr: false);
        $json = \json_decode($output, true);

        self::assertIsArray($json, "not JSON:\n$output");
        self::assertSame(['code', 'status', 'message', 'details'], \array_keys($json));
        // Without APP_DEBUG a failure carries an id, never the exception.
        self::assertArrayNotHasKey('error', $json['details'] ?? []);
    }

    public static function pubScripts(): array
    {
        return [
            'entrypoint' => ['pub/entrypoint.php'],
            'healthcheck' => ['pub/healthcheck.php'],
        ];
    }
}
