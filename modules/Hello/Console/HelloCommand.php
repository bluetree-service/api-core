<?php

declare(strict_types=1);

namespace Bluetree\Mod\Hello\Console;

use BlueConsole\Style;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A module command is any modules/<Name>/Console/*Command.php; bin/console
 * finds it by the file name, nothing has to register it.
 */
#[AsCommand(name: 'app:hello')]
class HelloCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Say hello from the example module.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new Style($input, $output, new FormatterHelper());
        $style->block('Hello World', null, 'fg=white;bg=green', ' ', true);

        return Command::SUCCESS;
    }
}
