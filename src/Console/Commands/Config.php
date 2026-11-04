<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Console\Commands;

use BlueCache\Cache as BlueCache;
use BlueCache\Storage\Redis;
use BlueConsole\Style;
use BlueRegister\Register;
use BlueRegister\RegisterException;
use Bluetree\Api\Core\ApiCore;
use Bluetree\Api\Core\Helpers\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Config extends Command
{
    /**
     * @var Register
     */
    protected Register $register;

    /**
     * @var InputInterface
     */
    protected InputInterface $input;

    /**
     * @var OutputInterface
     */
    protected OutputInterface $output;

    /**
     * Bound to stderr, because everything this command says about the
     * configuration is not the configuration. stdout carries the JSON alone, so
     * `app:config connections | jq` works; on a terminal both streams land in
     * front of you and it reads exactly as it always did.
     *
     * @var Style
     */
    protected Style $blueStyle;

    /**
     * @var FormatterHelper
     */
    protected FormatterHelper $formatter;

    /**
     * @var array
     */
    protected array $config = [];

    /**
     * @var BlueCache
     */
    protected BlueCache $cache;

    protected function configure(): void
    {
        $this->setName('app:config')
            ->setDescription('Show configuration.')
            ->setHelp('');

        $this->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'Show only this part of it, in dot notation: connections.database'
        );

        $this->addOption(
            'new',
            'N',
            null,
            'Merge configuration and show it before set to cache.'
        );

        $this->addOption(
            'env',
            'e',
            null,
            'Show required environment variables for application.'
        );
    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->register = new Register([]);

        $this->readRedisConnectData();
        $this->register();

        if ($this->input->getOption('new')) {
            $app = new ApiCore();
            $app->loadConfigurations();
            $shown = $this->render($app->returnConfigs());
        } else {
            $shown = $this->getRedisConfig();
        }

        if ($this->input->getOption('env')) {
            $this->blueStyle->newLine();
            $this->blueStyle->title('Required environment variables:');

            foreach (ApiCore::REQUIRED_ENV_VARIABLES as $envVariable) {
                $value = \getenv($envVariable);
                if ($value === false) {
                    $this->blueStyle->errorMessage("Environment variable '$envVariable' is not set.");
                } else {
                    // Whether it is there is the question. The value would end up
                    // in terminal scrollback and CI logs, and one of them is a password.
                    $this->blueStyle->writeln("$envVariable: <info>is set</info>");
                }
            }

            $this->blueStyle->newLine();
        }

        return $shown ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Printed as JSON rather than dumped.
     *
     * The configuration is JSON where it comes from, so this is the shape it
     * was written in and can be pasted back into. It also survives a pipe:
     * `app:config connections.database | jq` is how you find something in two
     * hundred lines of it. Dumping would mean carrying a debugging library into
     * the production image to render a nested array.
     */
    protected function render(array $config): bool
    {
        $path = $this->input->getArgument('path');

        if ($path !== null && $path !== '') {
            $config = $this->slice($config, $path);

            if ($config === null) {
                $this->blueStyle->errorMessage("Nothing at '$path'.");

                return false;
            }
        }

        $this->output->writeln(
            \json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return true;
    }

    /**
     * @return array|string|int|float|bool|null the value at a dotted path
     */
    protected function slice(array $config, string $path): mixed
    {
        $current = $config;

        foreach (\explode('.', $path) as $key) {
            if (!\is_array($current) || !\array_key_exists($key, $current)) {
                return null;
            }

            $current = $current[$key];
        }

        return $current;
    }

    protected function register(): void
    {
        try {
            $this->formatter = $this->register->factory(FormatterHelper::class);
            $errorOutput = $this->output instanceof ConsoleOutputInterface
                ? $this->output->getErrorOutput()
                : $this->output;
            $this->blueStyle = $this->register->factory(
                Style::class,
                [$this->input, $errorOutput, $this->formatter]
            );

            $this->cache = $this->register->factory(
                BlueCache::class,
                [[
                    'storage_class' => Redis::class,
                    'storage_servers' => [
                        $this->config['main']['cache']['host'],
                        $this->config['main']['cache']['port'],
                        $this->config['main']['cache']['database'] ?? 2,
                    ]
                ]]
            );
        } catch (RegisterException $exception) {
            throw new \DomainException('RegisterException: ' . $exception->getMessage());
        }
    }

    /**
     * @throws \JsonException
     */
    protected function readRedisConnectData(): void
    {
        $this->config = Json::readFile(ApiCore::configDir() . 'main.json');
    }

    protected function getRedisConfig(): bool
    {
        $this->blueStyle->title('Get config from cache...');

        $this->blueStyle->newLine();

        try {
            $cacheConfig = $this->cache->getItem(ApiCore::CACHE_CONFIG_NAME);
            if (empty($cacheConfig)) {
                $this->blueStyle->info('No config found in cache.');

                return false;
            }

            return $this->render($cacheConfig->get());
        } catch (\Exception $exception) {
            $this->blueStyle->errorMessage('Failed to read the config cache: ' . $exception->getMessage());

            return false;
        }
    }
}
