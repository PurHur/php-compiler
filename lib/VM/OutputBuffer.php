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

    /**
     * ob_get_flush() — pop active buffer, flush to parent/SAPI, return contents (issue #3753).
     *
     * php-src: ext/standard/output.c — like ob_end_flush but returns string|false.
     *
     * @return string|false
     */
    public static function getFlush(): string|bool
    {
        if ([] === self::$stack) {
            return false;
        }
        $content = array_pop(self::$stack);
        if ('' !== $content) {
            self::append($content);
        }

        return $content;
    }

    /** flush() — fflush stdout; ob buffers unchanged until ob_end_flush (issue #3388, php-src php_flush). */
    public static function flush(): void
    {
        if (\defined('STDOUT') && \is_resource(\STDOUT)) {
            @\fflush(\STDOUT);
        }
    }
}
