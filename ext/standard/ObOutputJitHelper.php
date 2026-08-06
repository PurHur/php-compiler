<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Output-buffer stack for compiled JIT/AOT modules (#9268, php-in-PHP).
 *
 * Mirrors {@see \PHPCompiler\VM\OutputBuffer} semantics for standalone JIT/AOT.
 * Direct stdout uses {@see phpc_ob_write_stdout_kernel} (not echo).
 *
 * NestedJIT AOT constraints (#27566 / #4941 / #12974 / #21469):
 * - No `array` static stack (no `__init__` → unusable hashtable).
 * - Use int lengths + seeded string slots (literal `'.'` prefix).
 * - Fold buffer cap as numeric literal (private const may be 0 under NestedJIT).
 * - Empty checks via `strlen`, not `'' === $s`.
 * php-src: ext/standard/output.c
 */
final class ObOutputJitHelper
{
    private static int $depth = 0;

    private static bool $implicitFlush = false;

    private static bool $urlRewriterRegistered = false;

    private static int $n0 = 0;
    private static int $n1 = 0;
    private static int $n2 = 0;
    private static int $n3 = 0;
    private static int $n4 = 0;
    private static int $n5 = 0;
    private static int $n6 = 0;
    private static int $n7 = 0;

    private static string $c0 = '.';
    private static string $c1 = '.';
    private static string $c2 = '.';
    private static string $c3 = '.';
    private static string $c4 = '.';
    private static string $c5 = '.';
    private static string $c6 = '.';
    private static string $c7 = '.';

    private static int $k0 = 0;
    private static int $k1 = 0;
    private static int $k2 = 0;
    private static int $k3 = 0;
    private static int $k4 = 0;
    private static int $k5 = 0;
    private static int $k6 = 0;
    private static int $k7 = 0;

    public static function reset(): void
    {
        self::$depth = 0;
        self::$implicitFlush = false;
        self::$urlRewriterRegistered = false;
        self::$n0 = 0;
        self::$n1 = 0;
        self::$n2 = 0;
        self::$n3 = 0;
        self::$n4 = 0;
        self::$n5 = 0;
        self::$n6 = 0;
        self::$n7 = 0;
        self::$k0 = 0;
        self::$k1 = 0;
        self::$k2 = 0;
        self::$k3 = 0;
        self::$k4 = 0;
        self::$k5 = 0;
        self::$k6 = 0;
        self::$k7 = 0;
        self::$c0 = '.';
        self::$c1 = '.';
        self::$c2 = '.';
        self::$c3 = '.';
        self::$c4 = '.';
        self::$c5 = '.';
        self::$c6 = '.';
        self::$c7 = '.';
    }

    public static function getLevel(): int
    {
        return self::$depth;
    }

    public static function bufferUsedAt(int $levelIdx): int
    {
        if ($levelIdx < 0 || $levelIdx >= self::$depth) {
            return 0;
        }

        return self::lenAt($levelIdx);
    }

    public static function start(): void
    {
        self::pushLevel(0);
    }

    public static function startWithGzhandler(): void
    {
        self::pushLevel(1);
    }

    public static function startWithUrlRewriter(): void
    {
        if (self::$urlRewriterRegistered) {
            return;
        }
        self::pushLevel(2);
        self::$urlRewriterRegistered = true;
    }

    public static function setImplicitFlush(int $enabled): void
    {
        self::$implicitFlush = 0 !== $enabled;
    }

    public static function appendString(string $chunk): int
    {
        if (0 === \strlen($chunk)) {
            return 0;
        }
        if (0 === self::$depth) {
            self::writeStdout($chunk);

            return 1;
        }
        $idx = self::$depth - 1;
        $cap = 65535;
        $used = self::lenAt($idx);
        if ($used >= $cap) {
            return 0;
        }
        $room = $cap - $used;
        if (\strlen($chunk) > $room) {
            $chunk = \substr($chunk, 0, $room);
        }
        // Concat onto seeded slot (never assign param alone — NestedJIT AOT abort, #27566).
        if (0 === $idx) {
            self::$c0 = self::$c0.$chunk;
            self::$n0 = \strlen(self::$c0) - 1;
        } elseif (1 === $idx) {
            self::$c1 = self::$c1.$chunk;
            self::$n1 = \strlen(self::$c1) - 1;
        } elseif (2 === $idx) {
            self::$c2 = self::$c2.$chunk;
            self::$n2 = \strlen(self::$c2) - 1;
        } elseif (3 === $idx) {
            self::$c3 = self::$c3.$chunk;
            self::$n3 = \strlen(self::$c3) - 1;
        } elseif (4 === $idx) {
            self::$c4 = self::$c4.$chunk;
            self::$n4 = \strlen(self::$c4) - 1;
        } elseif (5 === $idx) {
            self::$c5 = self::$c5.$chunk;
            self::$n5 = \strlen(self::$c5) - 1;
        } elseif (6 === $idx) {
            self::$c6 = self::$c6.$chunk;
            self::$n6 = \strlen(self::$c6) - 1;
        } else {
            self::$c7 = self::$c7.$chunk;
            self::$n7 = \strlen(self::$c7) - 1;
        }
        if (self::$implicitFlush) {
            self::flushBuffer();
        }

        return 0;
    }

    public static function hasActiveBuffer(): int
    {
        return 0 === self::$depth ? 0 : 1;
    }

    public static function getContents(): ?string
    {
        if (0 === self::$depth) {
            return null;
        }
        $idx = self::$depth - 1;
        if (0 === self::lenAt($idx)) {
            return '';
        }

        return self::contentAt($idx);
    }

    public static function getLength(): int
    {
        if (0 === self::$depth) {
            return -1;
        }

        return self::lenAt(self::$depth - 1);
    }

    public static function endClean(): int
    {
        if (0 === self::$depth) {
            return 0;
        }
        self::clearLevel(self::$depth - 1);
        self::$depth--;

        return 1;
    }

    public static function getClean(): ?string
    {
        if (0 === self::$depth) {
            return null;
        }
        $idx = self::$depth - 1;
        $content = self::contentAt($idx);
        self::clearLevel($idx);
        self::$depth--;

        return $content;
    }

    public static function endFlush(): int
    {
        if (0 === self::$depth) {
            return 0;
        }
        $content = self::popWithHandler();
        if (0 !== \strlen($content)) {
            self::appendString($content);
        }

        return 1;
    }

    public static function getFlush(): ?string
    {
        if (0 === self::$depth) {
            return null;
        }
        $content = self::popWithHandler();
        if (0 !== \strlen($content)) {
            self::appendString($content);
        }

        return $content;
    }

    public static function flushBuffer(): int
    {
        if (0 === self::$depth) {
            return 0;
        }
        $idx = self::$depth - 1;
        $content = self::contentAt($idx);
        self::clearContent($idx);
        if (0 === \strlen($content)) {
            return 1;
        }
        $kind = self::kindAt($idx);
        if (0 !== $kind) {
            $content = self::applyHandler($content, $kind);
        }
        if ($idx > 0) {
            self::appendTo($idx - 1, $content);
        } else {
            self::writeStdout($content);
        }

        return 1;
    }

    public static function clean(): int
    {
        if (0 === self::$depth) {
            return 0;
        }
        self::clearContent(self::$depth - 1);

        return 1;
    }

    public static function endAll(): void
    {
        while (0 !== self::$depth) {
            self::endFlush();
        }
        self::flushStdout();
    }

    public static function flushStdout(): void
    {
        if (\defined('STDOUT')) {
            @\fflush(\STDOUT);
        }
    }

    private static function pushLevel(int $kind): void
    {
        if (self::$depth >= 8) {
            return;
        }
        $idx = self::$depth;
        self::clearContent($idx);
        self::setKindAt($idx, $kind);
        self::$depth++;
    }

    private static function popWithHandler(): string
    {
        if (0 === self::$depth) {
            return '';
        }
        $idx = self::$depth - 1;
        $content = self::contentAt($idx);
        $kind = self::kindAt($idx);
        self::clearLevel($idx);
        self::$depth--;
        if (0 === $kind) {
            return $content;
        }

        return self::applyHandler($content, $kind);
    }

    private static function clearLevel(int $idx): void
    {
        self::clearContent($idx);
        self::setKindAt($idx, 0);
    }

    private static function clearContent(int $idx): void
    {
        if (0 === $idx) {
            self::$c0 = '.';
            self::$n0 = 0;
        } elseif (1 === $idx) {
            self::$c1 = '.';
            self::$n1 = 0;
        } elseif (2 === $idx) {
            self::$c2 = '.';
            self::$n2 = 0;
        } elseif (3 === $idx) {
            self::$c3 = '.';
            self::$n3 = 0;
        } elseif (4 === $idx) {
            self::$c4 = '.';
            self::$n4 = 0;
        } elseif (5 === $idx) {
            self::$c5 = '.';
            self::$n5 = 0;
        } elseif (6 === $idx) {
            self::$c6 = '.';
            self::$n6 = 0;
        } else {
            self::$c7 = '.';
            self::$n7 = 0;
        }
    }

    private static function appendTo(int $idx, string $chunk): void
    {
        if (0 === \strlen($chunk)) {
            return;
        }
        if (0 === $idx) {
            self::$c0 = self::$c0.$chunk;
            self::$n0 = \strlen(self::$c0) - 1;
        } elseif (1 === $idx) {
            self::$c1 = self::$c1.$chunk;
            self::$n1 = \strlen(self::$c1) - 1;
        } elseif (2 === $idx) {
            self::$c2 = self::$c2.$chunk;
            self::$n2 = \strlen(self::$c2) - 1;
        } elseif (3 === $idx) {
            self::$c3 = self::$c3.$chunk;
            self::$n3 = \strlen(self::$c3) - 1;
        } elseif (4 === $idx) {
            self::$c4 = self::$c4.$chunk;
            self::$n4 = \strlen(self::$c4) - 1;
        } elseif (5 === $idx) {
            self::$c5 = self::$c5.$chunk;
            self::$n5 = \strlen(self::$c5) - 1;
        } elseif (6 === $idx) {
            self::$c6 = self::$c6.$chunk;
            self::$n6 = \strlen(self::$c6) - 1;
        } else {
            self::$c7 = self::$c7.$chunk;
            self::$n7 = \strlen(self::$c7) - 1;
        }
    }

    private static function contentAt(int $idx): string
    {
        if (0 === self::lenAt($idx)) {
            return '';
        }
        $raw = self::rawAt($idx);
        if (\strlen($raw) <= 1) {
            return '';
        }

        return \substr($raw, 1);
    }

    private static function lenAt(int $idx): int
    {
        if (0 === $idx) {
            return self::$n0;
        }
        if (1 === $idx) {
            return self::$n1;
        }
        if (2 === $idx) {
            return self::$n2;
        }
        if (3 === $idx) {
            return self::$n3;
        }
        if (4 === $idx) {
            return self::$n4;
        }
        if (5 === $idx) {
            return self::$n5;
        }
        if (6 === $idx) {
            return self::$n6;
        }

        return self::$n7;
    }

    private static function rawAt(int $idx): string
    {
        if (0 === $idx) {
            return self::$c0;
        }
        if (1 === $idx) {
            return self::$c1;
        }
        if (2 === $idx) {
            return self::$c2;
        }
        if (3 === $idx) {
            return self::$c3;
        }
        if (4 === $idx) {
            return self::$c4;
        }
        if (5 === $idx) {
            return self::$c5;
        }
        if (6 === $idx) {
            return self::$c6;
        }

        return self::$c7;
    }

    private static function kindAt(int $idx): int
    {
        if (0 === $idx) {
            return self::$k0;
        }
        if (1 === $idx) {
            return self::$k1;
        }
        if (2 === $idx) {
            return self::$k2;
        }
        if (3 === $idx) {
            return self::$k3;
        }
        if (4 === $idx) {
            return self::$k4;
        }
        if (5 === $idx) {
            return self::$k5;
        }
        if (6 === $idx) {
            return self::$k6;
        }

        return self::$k7;
    }

    private static function setKindAt(int $idx, int $kind): void
    {
        if (0 === $idx) {
            self::$k0 = $kind;
        } elseif (1 === $idx) {
            self::$k1 = $kind;
        } elseif (2 === $idx) {
            self::$k2 = $kind;
        } elseif (3 === $idx) {
            self::$k3 = $kind;
        } elseif (4 === $idx) {
            self::$k4 = $kind;
        } elseif (5 === $idx) {
            self::$k5 = $kind;
        } elseif (6 === $idx) {
            self::$k6 = $kind;
        } else {
            self::$k7 = $kind;
        }
    }

    private static function applyHandler(string $content, int $kind): string
    {
        if (1 === $kind) {
            if (0 === \strlen($content)) {
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
        if (0 === \strlen($chunk)) {
            return;
        }
        \phpc_ob_write_stdout_kernel($chunk);
    }
}
