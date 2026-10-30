<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Tests;

use Bluetree\Api\Core\Health;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Nothing here needs a running cache or database: an unreachable one is
 * exactly the answer worth testing, and port 1 refuses at once.
 */
#[CoversClass(Health::class)]
class HealthTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = \sys_get_temp_dir() . '/api-core-health-' . \bin2hex(\random_bytes(4));
        \mkdir($this->logDir);
        // The probe writes as www-data when it can, and this runs as whoever.
        \chmod($this->logDir, 0777);

        \putenv('DB_USER=user');
        \putenv('DB_PASSWORD=secret');
    }

    protected function tearDown(): void
    {
        \putenv('DB_USER');
        \putenv('DB_PASSWORD');
        \putenv('HELLO_TOKEN');
        @\rmdir($this->logDir);
    }

    private function config(
        array $databases = ['default' => ['driver' => 'maria_db', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app']],
        array $modules = ['Hello' => ['required_env' => ['HELLO_TOKEN']]],
        ?string $logPath = null,
    ): array {
        return [
            'main' => [
                'cache' => ['host' => '127.0.0.1', 'port' => 1],
                'log' => ['log_path' => $logPath ?? $this->logDir],
            ],
            'connections' => ['database' => $databases],
            'modules' => $modules,
            'routes' => ['/hello' => []],
        ];
    }

    /**
     * @return list<array{status: string, message: string}>
     */
    private function answers(array $config, string $check): array
    {
        $results = (new Health($config))->run();

        return \array_values(\array_map(
            fn (array $result): array => ['status' => $result['status'], 'message' => $result['message']],
            \array_filter($results, fn (array $result): bool => $result['check'] === $check)
        ));
    }

    public function testRequiresTheCoreVariables(): void
    {
        \putenv('DB_PASSWORD');

        $answers = $this->answers($this->config(modules: ['Hello' => []]), 'environment');

        self::assertContains(['status' => Health::FAIL, 'message' => 'DB_PASSWORD is not set'], $answers);
        self::assertContains(['status' => Health::OK, 'message' => 'DB_USER is set'], $answers);
    }

    public function testRequiresWhatALoadedModuleDeclares(): void
    {
        $answers = $this->answers($this->config(), 'environment');

        self::assertContains(['status' => Health::FAIL, 'message' => 'HELLO_TOKEN is not set'], $answers);

        \putenv('HELLO_TOKEN=token');
        $answers = $this->answers($this->config(), 'environment');

        self::assertContains(['status' => Health::OK, 'message' => 'HELLO_TOKEN is set'], $answers);
    }

    public function testAnUnreachableCacheFails(): void
    {
        $answers = $this->answers($this->config(), 'cache');

        self::assertSame(Health::FAIL, $answers[0]['status']);
        self::assertStringStartsWith('redis did not answer', $answers[0]['message']);
    }

    public function testAnUnreachableDatabaseFails(): void
    {
        $answers = $this->answers($this->config(), 'database:default');

        self::assertSame(Health::FAIL, $answers[0]['status']);
    }

    public function testNoDatabasesIsAFailure(): void
    {
        self::assertSame(
            [['status' => Health::FAIL, 'message' => 'none are configured']],
            $this->answers($this->config(databases: []), 'databases')
        );
    }

    /**
     * The data layer cannot open it, so nothing in the application uses it.
     */
    public function testAnUnsupportedDriverIsOnlyAWarning(): void
    {
        $answers = $this->answers(
            $this->config(databases: ['other' => ['driver' => 'pgsql', 'host' => 'db', 'port' => 5432, 'database' => 'x']]),
            'database:other'
        );

        self::assertSame(
            [['status' => Health::WARNING, 'message' => "skipped, driver 'pgsql' is not one this api opens"]],
            $answers
        );
    }

    public function testAMissingLogDirectoryFails(): void
    {
        $answers = $this->answers($this->config(logPath: $this->logDir . '/missing'), 'log');

        self::assertSame([['status' => Health::FAIL, 'message' => $this->logDir . '/missing does not exist']], $answers);
    }

    public function testAWritableLogDirectoryPasses(): void
    {
        $answers = $this->answers($this->config(), 'log');

        self::assertNotContains(Health::FAIL, \array_column($answers, 'status'));
        self::assertSame(Health::OK, \end($answers)['status']);
    }

    public function testNoModulesIsAFailure(): void
    {
        self::assertSame(
            [['status' => Health::FAIL, 'message' => 'none were loaded']],
            $this->answers($this->config(modules: []), 'modules')
        );
    }

    public function testLoadedModulesAndRoutesAreListed(): void
    {
        self::assertSame(
            [
                ['status' => Health::OK, 'message' => 'Hello'],
                ['status' => Health::OK, 'message' => '1 routes'],
            ],
            $this->answers($this->config(), 'modules')
        );
    }

    public function testSummaryKeepsTheWorstStatusOfEachCheckAndNoMessages(): void
    {
        $results = [
            ['check' => 'environment', 'label' => 'Environment', 'status' => Health::OK, 'message' => 'A is set'],
            ['check' => 'environment', 'label' => 'Environment', 'status' => Health::FAIL, 'message' => 'B is not set'],
            ['check' => 'environment', 'label' => 'Environment', 'status' => Health::OK, 'message' => 'C is set'],
            ['check' => 'log', 'label' => 'Log directory', 'status' => Health::WARNING, 'message' => 'cannot test'],
            ['check' => 'log', 'label' => 'Log directory', 'status' => Health::OK, 'message' => '/log is writable'],
            ['check' => 'modules', 'label' => 'Modules', 'status' => Health::OK, 'message' => 'Hello'],
        ];

        self::assertSame(
            ['environment' => Health::FAIL, 'log' => Health::WARNING, 'modules' => Health::OK],
            Health::summary($results)
        );
        self::assertTrue(Health::failed($results));
        self::assertFalse(Health::failed(\array_slice($results, 3)));
    }
}
