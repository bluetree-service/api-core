<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Console;

use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Console output with the clock in front of every line.
 *
 * The long-running commands in the queue-listeners container write into the
 * container log, next to supervisord's own timestamped lines. Without this a
 * line like "Pobrano przed chwilą — przebieg pominięty." says nothing about
 * when it happened, which is the one thing worth knowing when reading back an
 * hour of the fetcher's passes.
 */
class TimestampedOutput extends ConsoleOutput
{
    protected function doWrite(string $message, bool $newline): void
    {
        // An empty write is a blank line for spacing; stamping it would only
        // add noise.
        if ($message !== '') {
            $message = \date('Y-m-d H:i:s') . ' ' . $message;
        }

        parent::doWrite($message, $newline);
    }
}
