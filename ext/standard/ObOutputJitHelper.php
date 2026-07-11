<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Output-buffer stack for compiled JIT/AOT modules (#9268, php-in-PHP).
 *
 * Mirrors {@see \PHPCompiler\VM\OutputBuffer} semantics for standalone JIT/AOT.
 * VM SSOT for user-facing builtins remains {@see NativeObStorage} / OutputBuffer.
 * php-src: ext/standard/output.c
 */
final class ObOutputJitHelper
{
    /** Nested JIT compile cannot resolve cross-unit class constants (#12974). */
    private const BUF_SIZE = 65536;

    /** Mirror {@see \PHPCompiler\VM\ObStackLimits} MAX_DEPTH. */
    private const MAX_DEPTH = 8;

    private const HANDLER_GZHANDLER = 'gzhandler';

    /** @var list<array{content: string, handler: ?string}> */
    private static array $stack = [];

    private static bool $implicitFlush = false;

    public static function reset(): void
    {
        self::$stack = [];
        self::$implicitFlush = false;
    }

    public static function getLevel(): int
    {
        return \count(self::$stack);
    }

    public static function bufferUsedAt(int $levelIdx): int
    {
        if ($levelIdx < 0 || $levelIdx >= \count(self::$stack)) {
            return 0;
        }

        return \strlen(self::$stack[$levelIdx]['content']);
    }

    public static function start(): void
    {
        self::pushLevel(null);
    }

    public static function startWithGzhandler(): void
    {
        self::pushLevel(self::HANDLER_GZHANDLER);
    }

    public static function setImplicitFlush(int $enabled): void
    {
        self::$implicitFlush = 0 !== $enabled;
    }

    /**
     * Append output; return 1 when bytes went direct to SAPI (no active buffer).
     */
    public static function appendString(string $chunk): int
    {
        if ('' === $chunk) {
            return 0;
        }
        if ([] === self::$stack) {
            self::writeStdout($chunk);

            return 1;
        }
        $idx = \count(self::$stack) - 1;
        $cap = self::BUF_SIZE - 1;
        $used = \strlen(self::$stack[$idx]['content']);
        if ($used >= $cap) {
            return 0;
        }
        $room = $cap - $used;
        if (\strlen($chunk) > $room) {
            $chunk = \substr($chunk, 0, $room);
        }
        self::$stack[$idx]['content'] .= $chunk;
        if (self::$implicitFlush) {
            self::flushBuffer();
        }

        return 0;
    }

    public static function hasActiveBuffer(): int
    {
        return [] === self::$stack ? 0 : 1;
    }

    public static function getContents(): ?string
    {
        if ([] === self::$stack) {
            return null;
        }

        return self::$stack[\count(self::$stack) - 1]['content'];
    }

    public static function getLength(): int
    {
        $contents = self::getContents();
        if (null === $contents) {
            return -1;
        }

        return \strlen($contents);
    }

    public static function endClean(): int
    {
        if ([] === self::$stack) {
            return 0;
        }
        \array_pop(self::$stack);

        return 1;
    }

    public static function getClean(): ?string
    {
        if ([] === self::$stack) {
            return null;
        }
        $level = \array_pop(self::$stack);

        return $level['content'];
    }

    public static function endFlush(): int
    {
        if ([] === self::$stack) {
            return 0;
        }
        $content = self::popWithHandler();
        if ('' !== $content) {
            self::appendString($content);
        }

        return 1;
    }

    public static function getFlush(): ?string
    {
        if ([] === self::$stack) {
            return null;
        }
        $content = self::popWithHandler();
        if ('' !== $content) {
            self::appendString($content);
        }

        return $content;
    }

    public static function flushBuffer(): int
    {
        if ([] === self::$stack) {
            return 0;
        }
        $idx = \count(self::$stack) - 1;
        $content = self::$stack[$idx]['content'];
        self::$stack[$idx]['content'] = '';
        if ('' === $content) {
            return 1;
        }
        $handler = self::$stack[$idx]['handler'];
        if (null !== $handler) {
            $content = self::applyHandler($content, $handler);
        }
        if ($idx > 0) {
            self::$stack[$idx - 1]['content'] .= $content;
        } else {
            self::writeStdout($content);
        }

        return 1;
    }

    public static function clean(): int
    {
        if ([] === self::$stack) {
            return 0;
        }
        $idx = \count(self::$stack) - 1;
        self::$stack[$idx]['content'] = '';

        return 1;
    }

    public static function endAll(): void
    {
        while ([] !== self::$stack) {
            self::endFlush();
        }
        self::flushStdout();
    }

    public static function flushStdout(): void
    {
        if (\defined('STDOUT') && \is_resource(\STDOUT)) {
            @\fflush(\STDOUT);
        }
    }

    private static function pushLevel(?string $handler): void
    {
        if (\count(self::$stack) >= self::MAX_DEPTH) {
            return;
        }
        self::$stack[] = ['content' => '', 'handler' => $handler];
    }

    private static function popWithHandler(): string
    {
        $level = \array_pop(self::$stack);
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
        if (self::HANDLER_GZHANDLER === $handlerName) {
            if ('' === $content) {
                return '';
            }
            $compressed = ZlibEncodeJitHelper::gzencode($content, -1, \ZLIB_ENCODING_GZIP);
            if (false === $compressed) {
                return $content;
            }

            return $compressed;
        }

        return $content;
    }

    private static function writeStdout(string $chunk): void
    {
        if ('' === $chunk) {
            return;
        }
        echo $chunk;
    }
}
