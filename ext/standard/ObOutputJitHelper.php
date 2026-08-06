<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Output-buffer stack for compiled JIT/AOT modules (#9268, php-in-PHP).
 *
 * Mirrors {@see \PHPCompiler\VM\OutputBuffer} semantics for standalone JIT/AOT.
 * Direct stdout uses {@see phpc_ob_write_stdout_kernel} (not echo) so NestedJIT does not
 * recurse through `__phpc_ob_echo_*` (#21469 / #21066).
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

    /** Idempotent URL-Rewriter registration (#27566). */
    private static bool $urlRewriterRegistered = false;

    public static function reset(): void
    {
        self::$stack = [];
        self::$implicitFlush = false;
        self::$urlRewriterRegistered = false;
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

    /**
     * Register URL-Rewriter OB level (idempotent) for AOT/JIT (#27566).
     * Shares {@see $stack} with echo — via `__phpc_ob_start_with_url_rewriter`.
     */
    public static function startWithUrlRewriter(): void
    {
        if (self::$urlRewriterRegistered) {
            return;
        }
        self::pushLevel('URL-Rewriter');
        self::$urlRewriterRegistered = true;
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
        // Prefer getLevel() over `0 === self::getLevel()` — NestedJIT standalone AOT
        // lowers empty-array identity to __hashtable__alloc vs static (never equal; #21469).
        if (0 === self::getLevel()) {
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
        return 0 === self::getLevel() ? 0 : 1;
    }

    public static function getContents(): ?string
    {
        if (0 === self::getLevel()) {
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
        if (0 === self::getLevel()) {
            return 0;
        }
        \array_pop(self::$stack);

        return 1;
    }

    public static function getClean(): ?string
    {
        if (0 === self::getLevel()) {
            return null;
        }
        $level = \array_pop(self::$stack);

        return $level['content'];
    }

    public static function endFlush(): int
    {
        if (0 === self::getLevel()) {
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
        if (0 === self::getLevel()) {
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
        if (0 === self::getLevel()) {
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
        if (0 === self::getLevel()) {
            return 0;
        }
        $idx = \count(self::$stack) - 1;
        self::$stack[$idx]['content'] = '';

        return 1;
    }

    public static function endAll(): void
    {
        while (0 !== self::getLevel()) {
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
        if ('URL-Rewriter' === \$handlerName) {
            // Flush via separate NestedJIT in ensureUrlRewriterStack user module (#27566).
            // Identity here keeps helper-unit emit healthy for getLevel.
            return \$content;
        }

        return $content;
    }

    private static function writeStdout(string $chunk): void
    {
        if ('' === $chunk) {
            return;
        }
        // Must not use echo — NestedJIT lowers echo to __phpc_ob_echo_* → append_bytes → here (#21469 / #21066).
        \phpc_ob_write_stdout_kernel($chunk);
    }
}
