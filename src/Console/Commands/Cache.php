<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Console\Commands;

use BlueCache\Storage\Redis;
use BlueConsole\Style;
use BlueRegister\Register;
use BlueRegister\RegisterException;
use Bluetree\Api\Core\Helpers\Json;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Bluetree\Api\Core\ApiCore;
use BlueCache\Cache as BlueCache;

#[AsCommand(name: 'app:cache')]
class Cache extends Command
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
        $this->setName('app:cache')
            ->setDescription('Clear cache.')
            ->setHelp('');
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->register = new Register([]);

        $this->readRedisConnectData();
        $this->register();

        $this->blueStyle->title('Clearing cache...');

        $hasError = false;

        foreach ($this->config['main']['main_cache_blocks'] as $cacheType) {
            $this->clear($cacheType) || $hasError = true;
        }

        $this->blueStyle->newLine();

        if ($hasError) {
            $this->blueStyle->error(
                'Some cache types could not be cleared. Please check the error messages above for details.'
            );
            return Command::FAILURE;
        }

        $this->blueStyle->success('Cache clearing process completed.');

        return Command::SUCCESS;
    }

    protected function register(): void
    {
        try {
            $this->formatter = $this->register->factory(FormatterHelper::class);
            $this->blueStyle = $this->register->factory(Style::class, [$this->input, $this->output, $this->formatter]);

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

    /**
     * @throws \Exception
     */
    protected function clear(string $cacheType): bool
    {
        $this->blueStyle->infoMessage("Clearing $cacheType cache...");

        try {
            $this->cache->deleteItem($cacheType);
        } catch (\Exception $exception) {
            $this->blueStyle->errorMessage("Failed to access $cacheType cache: " . $exception->getMessage());
            return false;
        }

        $this->blueStyle->okMessage("$cacheType cache cleared.");
        return true;
    }
}
