<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * readline() — interactive CLI line input (ext/readline/readline.c, #3776, #6216, #8028).
 *
 * PHP-owned implementation: STDIN fgets fallback + in-memory history. No host ext/readline delegation.
 */
final class VmReadline
{
    /** @var list<string> */
    private static array $history = [];

    private static string $lineBuffer = '';

    private static int $point = 0;

    private static string $readlineName = 'php';

    private static int $attemptedCompletionOver = 0;

    /** readline_info() library_version — GNU readline reports rl_library_version (#15262). */
    private static string $libraryVersion = '';

    public static function read(?string $prompt): string|false
    {
        return self::readStdinFallback($prompt);
    }

    public static function addHistory(string $line): bool
    {
        self::$history[] = $line;

        return true;
    }

    public static function clearHistory(): bool
    {
        self::$history = [];

        return true;
    }

    public static function listHistory(): HashTable
    {
        return self::packedStringListToHashTable(self::$history);
    }

    public static function writeHistory(?string $filename = null): bool
    {
        if (null === $filename) {
            return false;
        }
        $content = '' === self::$history ? '' : \implode("\n", self::$history)."\n";

        return false !== VmFs::filePutContents($filename, $content);
    }

    public static function readHistory(?string $filename = null): bool
    {
        if (null === $filename) {
            return false;
        }
        self::$history = [];
        $lines = VmFs::file($filename, StdlibConstants::FILE_IGNORE_NEW_LINES);
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
        return self::infoFallback($varname, $newvalue, $hasNewvalue);
    }

    public static function completionFunction(mixed $callback): bool
    {
        return false;
    }

    public static function callbackHandlerInstall(string $prompt, mixed $callback): bool
    {
        return false;
    }

    public static function callbackReadChar(): void
    {
    }

    public static function callbackHandlerRemove(): bool
    {
        return false;
    }

    public static function onNewLine(): void
    {
    }

    public static function redisplay(): void
    {
    }

    /**
     * @return HashTable|string|int|bool
     */
    private static function infoFallback(?string $varname, mixed $newvalue, bool $hasNewvalue): HashTable|string|int|bool
    {
        if (null === $varname) {
            return self::assocArrayToHashTable([
                'line_buffer' => self::$lineBuffer,
                'point' => self::$point,
                'end' => self::lineBufferEnd(),
                'readline_name' => self::$readlineName,
                'attempted_completion_over' => self::$attemptedCompletionOver,
                'library_version' => self::$libraryVersion,
            ]);
        }

        $key = \strtolower($varname);
        if ('line_buffer' === $key) {
            if ($hasNewvalue) {
                $old = self::$lineBuffer;
                self::$lineBuffer = \is_string($newvalue) ? $newvalue : (string) $newvalue;
                self::$point = self::lineBufferEnd();

                return $old;
            }

            return self::$lineBuffer;
        }
        if ('point' === $key) {
            if ($hasNewvalue) {
                $old = self::$point;
                self::$point = (int) $newvalue;

                return $old;
            }

            return self::$point;
        }
        if ('end' === $key) {
            if ($hasNewvalue) {
                $old = self::lineBufferEnd();
                self::$lineBuffer = \substr(self::$lineBuffer, 0, (int) $newvalue);
                if (self::$point > self::lineBufferEnd()) {
                    self::$point = self::lineBufferEnd();
                }

                return $old;
            }

            return self::lineBufferEnd();
        }
        if ('library_version' === $key) {
            if ($hasNewvalue) {
                $old = self::$libraryVersion;
                self::$libraryVersion = \is_string($newvalue) ? $newvalue : (string) $newvalue;

                return $old;
            }

            return self::$libraryVersion;
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

    private static function lineBufferEnd(): int
    {
        return \strlen(self::$lineBuffer);
    }

    private static function readStdinFallback(?string $prompt): string|false
    {
        if (!\defined('STDIN')) {
            return false;
        }

        $stdin = \STDIN;
        if (!\is_resource($stdin) && !($stdin instanceof \Socket)) {
            return false;
        }

        // Zend readline.c — no prompt echo when stdin is not a TTY (#12301).
        if (!\stream_isatty($stdin)) {
            return false;
        }

        if (null !== $prompt && '' !== $prompt) {
            echo $prompt;
            if (\defined('STDOUT') && (\is_resource(\STDOUT) || \STDOUT instanceof \Socket)) {
                \fflush(\STDOUT);
            }
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

        self::$lineBuffer = $line;
        self::$point = \strlen($line);

        return $line;
    }
}
