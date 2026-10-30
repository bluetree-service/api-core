<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Console\Commands;

use BlueCache\Cache as BlueCache;
use BlueCache\Storage\Redis;
use BlueConsole\Style;
use BlueRegister\Register;
use BlueRegister\RegisterException;
use Bluetree\Api\Core\ApiCore;
use Bluetree\Api\Core\Helpers\Db;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Answers whether this installation can reach what it depends on.
 *
 * Written to be run after a deploy: everything it checks has, at some point,
 * been the reason something did not work — an environment variable that never
 * made it into the container, a database that was never migrated, a cache on
 * the wrong host.
 */
#[AsCommand(name: 'app:health')]
class Healthcheck extends Command
{
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

    protected Register $register;
    protected Style $blueStyle;
    protected array $config = [];
    protected bool $failed = false;

    protected function configure(): void
    {
        $this->setName('app:health')
            ->setDescription('Check that the databases, the cache and the environment are reachable.')
            ->setHelp('Exits non-zero when any check fails, so a deploy can stop on it.');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->register = new Register([]);
        $this->blueStyle = $this->register->factory(
            Style::class,
            [$input, $output, $this->register->factory(FormatterHelper::class)]
        );

        $core = new ApiCore();
        $core->run();
        $this->config = $core->returnConfigs();

        $this->blueStyle->title('Health check');

        $this->checkEnvironment();
        $this->checkCache();
        $this->checkDatabases();
        $this->checkLogDirectory();
        $this->checkModules();

        $this->blueStyle->newLine();

        if ($this->failed) {
            $this->blueStyle->error('Something this installation depends on is not reachable.');

            return Command::FAILURE;
        }

        $this->blueStyle->success('Everything answered.');

        return Command::SUCCESS;
    }

    /**
     * The variables the container has to carry. Empty ones are the usual reason
     * a freshly deployed api cannot open anything.
     */
    protected function checkEnvironment(): void
    {
        $this->blueStyle->infoMessage('Environment');

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
                $this->fail("  $name is not set");
                continue;
            }

            $this->blueStyle->okMessage("  $name is set");
        }
    }

    protected function checkCache(): void
    {
        $cache = $this->config['main']['cache'] ?? [];
        $where = ($cache['host'] ?? '?') . ':' . ($cache['port'] ?? '?');

        $this->blueStyle->infoMessage("Cache ($where)");

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

            $this->blueStyle->okMessage('  redis answered');
        } catch (RegisterException | \Throwable $exception) {
            $this->fail('  redis did not answer: ' . $exception->getMessage());
        }
    }

    /**
     * Every connection the merged configuration names, module ones included.
     */
    protected function checkDatabases(): void
    {
        $connections = $this->config['connections']['database'] ?? [];

        if ($connections === []) {
            $this->fail('Databases: none are configured');

            return;
        }

        foreach ($connections as $name => $connection) {
            $driver = $connection['driver'] ?? 'maria_db';
            $where = ($connection['host'] ?? '?') . ':' . ($connection['port'] ?? '?')
                . '/' . ($connection['database'] ?? '?');

            $this->blueStyle->infoMessage("Database $name ($where)");

            if (!\in_array($driver, self::SUPPORTED_DRIVERS, true)) {
                // Not a failure: the data layer cannot open it, so nothing in
                // the application is using it either.
                $this->blueStyle->warningMessage("  skipped, driver '$driver' is not one this api opens");
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
            $this->blueStyle->okMessage('  connected');

            $tables = $db->queryOne(
                'SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE()'
            );
            $total = (int)($tables['total'] ?? 0);

            if ($total === 0) {
                // What an unmigrated database looks like.
                $this->fail('  no tables — has it been migrated?');

                return;
            }

            $this->blueStyle->okMessage("  $total tables");
        } catch (\Throwable $exception) {
            $this->fail('  ' . $exception->getMessage());
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

        $this->blueStyle->infoMessage('Log directory');

        if (!\is_dir($path)) {
            $this->fail("  $path does not exist");

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
            $this->blueStyle->warningMessage(
                '  cannot test as ' . self::WEB_USER . ', answering for the current user instead'
            );

            if (!\is_writable($path)) {
                $this->fail("  $path is not writable");

                return;
            }

            $this->blueStyle->okMessage("  $path is writable");

            return;
        }

        if ($failure !== null) {
            $this->fail("  $failure");

            return;
        }

        $this->blueStyle->okMessage("  $path is writable by " . self::WEB_USER);
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
        if (!\function_exists('exec') || \posix_getpwnam(self::WEB_USER) === false) {
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

        $this->blueStyle->infoMessage('Modules');

        if ($modules === []) {
            $this->fail('  none were loaded');

            return;
        }

        $this->blueStyle->okMessage('  ' . \implode(', ', $modules));
        $this->blueStyle->okMessage("  $routes routes");
    }

    protected function fail(string $message): void
    {
        $this->failed = true;
        $this->blueStyle->errorMessage($message);
    }
}
