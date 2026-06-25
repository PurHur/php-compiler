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
    /** @var list<array{content: string, handler: ?string}> */
    private static array $stack = [];

    private static bool $implicitFlush = false;

    private static ?Context $activeContext = null;

    public static function reset(): void
    {
        self::$stack = [];
        self::$implicitFlush = false;
        self::$activeContext = null;
        SapiOutput::reset();
        HeaderCallbackQueue::reset();
    }

    public static function setActiveContext(?Context $ctx): void
    {
        self::$activeContext = $ctx;
    }

    public static function setImplicitFlush(bool $on): void
    {
        self::$implicitFlush = $on;
    }

    public static function isImplicitFlush(): bool
    {
        return self::$implicitFlush;
    }

    public static function getLevel(): int
    {
        return count(self::$stack);
    }

    /**
     * @return list<string> copy of active buffer contents per level (outer → inner)
     */
    public static function getBuffers(): array
    {
        $buffers = [];
        foreach (self::$stack as $level) {
            $buffers[] = $level['content'];
        }

        return $buffers;
    }

    /**
     * @return list<?string> handler name per buffer level (null = default handler)
     */
    public static function getHandlerNames(): array
    {
        $names = [];
        foreach (self::$stack as $level) {
            $names[] = $level['handler'];
        }

        return $names;
    }

    public static function start(?string $handlerName = null): void
    {
        if (self::getLevel() >= ObStackLimits::MAX_DEPTH) {
            return;
        }
        self::$stack[] = ['content' => '', 'handler' => $handlerName];
    }

    public static function append(string $chunk, ?string $file = null, int $line = 0): void
    {
        if ([] === self::$stack) {
            SapiOutput::markStarted($file, $line);
            echo $chunk;
            if (self::$implicitFlush) {
                self::flush();
            }

            return;
        }
        $idx = count(self::$stack) - 1;
        self::$stack[$idx]['content'] .= $chunk;
        if (self::$implicitFlush) {
            self::flush();
        }
    }

    public static function getClean(): string
    {
        if ([] === self::$stack) {
            return '';
        }
        $level = array_pop(self::$stack);

        return $level['content'];
    }

    /** ob_get_contents() — read active buffer without ending (ext/standard/output.c, issue #3236). */
    public static function getContents(): ?string
    {
        if ([] === self::$stack) {
            return null;
        }

        return self::$stack[count(self::$stack) - 1]['content'];
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
        $content = self::popWithHandler();
        if ('' !== $content) {
            self::append($content);
        }
    }

    /**
     * ob_flush() — flush active buffer to parent/SAPI without ending level (ext/standard/output.c, #3588).
     */
    public static function flushBuffer(): bool
    {
        if ([] === self::$stack) {
            return false;
        }
        $idx = \count(self::$stack) - 1;
        $content = self::$stack[$idx]['content'];
        self::$stack[$idx]['content'] = '';

        if ('' !== $content) {
            $handler = self::$stack[$idx]['handler'];
            if (null !== $handler) {
                $content = self::applyHandler($content, $handler);
            }
            if ($idx > 0) {
                self::$stack[$idx - 1]['content'] .= $content;
            } else {
                SapiOutput::markStarted();
                echo $content;
            }
        }

        return true;
    }

    /**
     * ob_clean() — discard active buffer contents without ending level (ext/standard/output.c, #3588).
     */
    public static function clean(): bool
    {
        if ([] === self::$stack) {
            return false;
        }
        $idx = \count(self::$stack) - 1;
        self::$stack[$idx]['content'] = '';

        return true;
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
        $content = self::popWithHandler();
        if ('' !== $content) {
            self::append($content);
        }

        return $content;
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

    private static function popWithHandler(): string
    {
        $level = array_pop(self::$stack);
        if (null === $level) {
            return '';
        }
        $content = $level['content'];
        $handler = $level['handler'];
        if (null === $handler) {
            return $content;
        }

        return self::applyHandler($content, $handler);
    }

    private static function applyHandler(string $content, string $handlerName): string
    {
        return OutputBufferHandlers::apply($content, $handlerName, self::$activeContext);
    }
}
