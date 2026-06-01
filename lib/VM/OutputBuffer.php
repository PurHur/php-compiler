<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Request-scoped output buffering for VM scripts (issue #118).
 *
 * When the stack is non-empty, echo/print append to the active buffer instead of stdout.
 */
final class OutputBuffer
{
    /** @var list<string> */
    private static array $stack = [];

    public static function reset(): void
    {
        self::$stack = [];
        SapiOutput::reset();
        HeaderCallbackQueue::reset();
    }

    public static function getLevel(): int
    {
        return count(self::$stack);
    }

    public static function start(): void
    {
        self::$stack[] = '';
    }

    public static function append(string $chunk): void
    {
        if ([] === self::$stack) {
            SapiOutput::markStarted();
            echo $chunk;

            return;
        }
        $idx = count(self::$stack) - 1;
        self::$stack[$idx] .= $chunk;
    }

    public static function getClean(): string
    {
        if ([] === self::$stack) {
            return '';
        }

        return array_pop(self::$stack);
    }

    /** ob_get_contents() — read active buffer without ending (ext/standard/output.c, issue #3236). */
    public static function getContents(): ?string
    {
        if ([] === self::$stack) {
            return null;
        }

        return self::$stack[count(self::$stack) - 1];
    }

    /** ob_get_length() — byte length of active buffer (issue #3236). */
    public static function getLength(): ?int
    {
        $contents = self::getContents();
        if (null === $contents) {
            return null;
        }

        return strlen($contents);
    }

    /** ob_end_clean() — discard active buffer and pop level (issue #3236). */
    public static function endClean(): bool
    {
        if ([] === self::$stack) {
            return false;
        }
        array_pop(self::$stack);

        return true;
    }

    public static function endFlush(): void
    {
        if ([] === self::$stack) {
            return;
        }
        self::append(array_pop(self::$stack));
    }

    /** flush() — sapi_flush / fflush(stdout) (issue #3388, php-src basic_functions.c PHP_FUNCTION(flush)). */
    public static function flush(): void
    {
        if (\defined('STDOUT') && \is_resource(\STDOUT)) {
            @\fflush(\STDOUT);
        }
    }

    /** php_output_end_all parity — flush remaining ob levels at request shutdown (issue #3675). */
    public static function endAllAtShutdown(): void
    {
        while ([] !== self::$stack) {
            self::endFlush();
        }
    }
}
