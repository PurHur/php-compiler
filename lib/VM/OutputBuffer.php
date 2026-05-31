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
