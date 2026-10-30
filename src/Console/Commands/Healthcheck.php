<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Console\Commands;

use BlueConsole\Style;
use BlueRegister\Register;
use Bluetree\Api\Core\ApiCore;
use Bluetree\Api\Core\Health;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints every answer Health collects, in full. What is checked and why lives
 * in Health; this only renders it.
 */
#[AsCommand(name: 'app:health')]
class Healthcheck extends Command
{
    protected Register $register;
    protected Style $blueStyle;

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

        /** @var Health $health */
        $health = $this->register->factory(Health::class, [$core->returnConfigs(), $this->register]);
        $results = $health->run();

        $this->blueStyle->title('Health check');

        $label = null;

        foreach ($results as $result) {
            if ($result['label'] !== $label) {
                $label = $result['label'];
                $this->blueStyle->infoMessage($label);
            }

            $message = '  ' . $result['message'];

            match ($result['status']) {
                Health::OK => $this->blueStyle->okMessage($message),
                Health::WARNING => $this->blueStyle->warningMessage($message),
                default => $this->blueStyle->errorMessage($message),
            };
        }

        $this->blueStyle->newLine();

        if (Health::failed($results)) {
            $this->blueStyle->error('Something this installation depends on is not reachable.');

            return Command::FAILURE;
        }

        $this->blueStyle->success('Everything answered.');

        return Command::SUCCESS;
    }
}
