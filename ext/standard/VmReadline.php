<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * readline() — interactive CLI line input (ext/readline/readline.c, #3776, #6216).
 *
 * Uses host ext/readline when loaded; otherwise reads one line from STDIN via fgets.
 * History/info helpers: host delegation when available, in-memory fallback (#7059).
 */
final class VmReadline
{
    /** @var list<string> */
    private static array $history = [];

    private static string $lineBuffer = '';

    private static string $readlineName = 'php';

    private static int $attemptedCompletionOver = 0;

    public static function read(?string $prompt): string|false
    {
        if (\function_exists('readline')) {
            $line = null === $prompt ? \readline() : \readline($prompt);

            return false === $line ? false : $line;
        }

        return self::readStdinFallback($prompt);
    }

    public static function addHistory(string $line): bool
    {
        if (\function_exists('readline_add_history')) {
            return \readline_add_history($line);
        }
        self::$history[] = $line;

        return true;
    }

    public static function clearHistory(): bool
    {
        if (\function_exists('readline_clear_history')) {
            return \readline_clear_history();
        }
        self::$history = [];

        return true;
    }

    public static function listHistory(): HashTable
    {
        if (\function_exists('readline_list_history')) {
            return self::packedStringListToHashTable(\readline_list_history());
        }

        return self::packedStringListToHashTable(self::$history);
    }

    public static function writeHistory(?string $filename = null): bool
    {
        if (\function_exists('readline_write_history')) {
            return \readline_write_history($filename);
        }
        if (null === $filename) {
            return false;
        }
        $content = '' === self::$history ? '' : \implode("\n", self::$history)."\n";

        return false !== \file_put_contents($filename, $content);
    }

    public static function readHistory(?string $filename = null): bool
    {
        if (\function_exists('readline_read_history')) {
            return \readline_read_history($filename);
        }
        if (null === $filename || !\is_readable($filename)) {
            return false;
        }
        self::$history = [];
        $lines = \file($filename, \FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return false;
        }
        foreach ($lines as $line) {
            self::$history[] = (string) $line;
        }

        return true;
    }

    /**
     * @return HashTable|string|int|bool
     */
    public static function info(?string $varname = null, mixed $newvalue = null, bool $hasNewvalue = false): HashTable|string|int|bool
    {
        if (\function_exists('readline_info')) {
            if (null === $varname) {
                return self::normalizeInfoResult(\readline_info());
            }
            if (!$hasNewvalue) {
                return self::normalizeInfoResult(\readline_info($varname));
            }

            return self::normalizeInfoResult(\readline_info($varname, $newvalue));
        }

        return self::infoFallback($varname, $newvalue, $hasNewvalue);
    }

    public static function completionFunction(mixed $callback): bool
    {
        if (\function_exists('readline_completion_function') && \is_string($callback)) {
            return \readline_completion_function($callback);
        }

        return false;
    }

    public static function callbackHandlerInstall(string $prompt, mixed $callback): bool
    {
        if (\function_exists('readline_callback_handler_install') && \is_string($callback)) {
            return \readline_callback_handler_install($prompt, $callback);
        }

        return false;
    }

    public static function callbackReadChar(): void
    {
        if (\function_exists('readline_callback_read_char')) {
            \readline_callback_read_char();
        }
    }

    public static function callbackHandlerRemove(): bool
    {
        if (\function_exists('readline_callback_handler_remove')) {
            return \readline_callback_handler_remove();
        }

        return false;
    }

    public static function onNewLine(): void
    {
        if (\function_exists('readline_on_new_line')) {
            \readline_on_new_line();
        }
    }

    public static function redisplay(): void
    {
        if (\function_exists('readline_redisplay')) {
            \readline_redisplay();
        }
    }

    /**
     * @return HashTable|string|int|bool
     */
    private static function infoFallback(?string $varname, mixed $newvalue, bool $hasNewvalue): HashTable|string|int|bool
    {
        if (null === $varname) {
            return self::assocArrayToHashTable([
                'line_buffer' => self::$lineBuffer,
                'readline_name' => self::$readlineName,
                'attempted_completion_over' => self::$attemptedCompletionOver,
            ]);
        }

        $key = \strtolower($varname);
        if ('line_buffer' === $key) {
            if ($hasNewvalue) {
                $old = self::$lineBuffer;
                self::$lineBuffer = \is_string($newvalue) ? $newvalue : (string) $newvalue;

                return $old;
            }

            return self::$lineBuffer;
        }
        if ('readline_name' === $key) {
            if ($hasNewvalue) {
                $old = self::$readlineName;
                self::$readlineName = \is_string($newvalue) ? $newvalue : (string) $newvalue;

                return $old;
            }

            return self::$readlineName;
        }
        if ('attempted_completion_over' === $key) {
            if ($hasNewvalue) {
                $old = self::$attemptedCompletionOver;
                self::$attemptedCompletionOver = (int) $newvalue;

                return $old;
            }

            return self::$attemptedCompletionOver;
        }

        return '';
    }

    private static function normalizeInfoResult(mixed $result): HashTable|string|int|bool
    {
        if (\is_array($result)) {
            return self::assocArrayToHashTable($result);
        }
        if (\is_bool($result)) {
            return $result;
        }
        if (\is_int($result)) {
            return $result;
        }

        return (string) $result;
    }

    /**
     * @param list<string> $lines
     */
    private static function packedStringListToHashTable(array $lines): HashTable
    {
        $ht = new HashTable();
        foreach ($lines as $line) {
            $value = new Variable();
            $value->string((string) $line);
            $ht->append($value);
        }

        return $ht;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function assocArrayToHashTable(array $data): HashTable
    {
        $ht = new HashTable();
        foreach ($data as $key => $value) {
            $slot = new Variable();
            if (\is_bool($value)) {
                $slot->bool($value);
            } elseif (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_float($value)) {
                $slot->float($value);
            } else {
                $slot->string((string) $value);
            }
            $ht->add((string) $key, $slot);
        }

        return $ht;
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
