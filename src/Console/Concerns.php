<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Ledger\Ledger;
use ChangeCouncil\Models\Change;

/** Shared helpers for the council commands. */
trait Concerns
{
    /** --file=<path>, else stdin. */
    protected function readInput(): ?string
    {
        $file = $this->option('file');
        if (is_string($file) && $file !== '') {
            if (! is_file($file)) {
                $this->error("No such file: {$file}");

                return null;
            }

            return (string) file_get_contents($file);
        }
        if (stream_isatty(STDIN)) {
            $this->error('Pass --file=<path> or pipe the input on stdin.');

            return null;
        }

        return (string) stream_get_contents(STDIN);
    }

    protected function change(Ledger $ledger, string $ref): ?Change
    {
        $change = $ledger->find($ref);
        if ($change === null) {
            $this->error("No Change {$ref}.");
        }

        return $change;
    }

    protected function emitJson(mixed $value): void
    {
        $this->output->writeln(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
