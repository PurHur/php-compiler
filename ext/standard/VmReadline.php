<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * readline() — interactive CLI line input (ext/readline/readline.c, #3776, #6216).
 *
 * Uses host ext/readline when loaded; otherwise reads one line from STDIN via fgets.
 */
final class VmReadline
{
    public static function read(?string $prompt): string|false
    {
        if (\function_exists('readline')) {
            $line = null === $prompt ? \readline() : \readline($prompt);

            return false === $line ? false : $line;
        }

        return self::readStdinFallback($prompt);
    }

    private static function readStdinFallback(?string $prompt): string|false
    {
        if (null !== $prompt && '' !== $prompt) {
            echo $prompt;
            if (\defined('STDOUT') && (\is_resource(\STDOUT) || \STDOUT instanceof \Socket)) {
                \fflush(\STDOUT);
            }
        }

        if (!\defined('STDIN')) {
            return false;
        }

        $stdin = \STDIN;
        if (!\is_resource($stdin) && !($stdin instanceof \Socket)) {
            return false;
        }

        $line = \fgets($stdin);
        if (false === $line) {
            return false;
        }

        if (\str_ends_with($line, "\n")) {
            $line = \substr($line, 0, -1);
        }
        if (\str_ends_with($line, "\r")) {
            $line = \substr($line, 0, -1);
        }

        return $line;
    }
}
