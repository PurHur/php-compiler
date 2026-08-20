<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * get_meta_tags() for compiled JIT/AOT modules (#9338, #33051, php-in-PHP).
 *
 * Thin AOT: NestedJIT must not return {@see \PHPCompiler\VM\HashTable} (#27551 / #26942).
 * Materialize via {@see phpc_native_ht_alloc} + {@see phpc_native_ht_set_string_key}
 * (peer parse_str #13827). {@see \PHPCompiler\JIT\Builtin\MetaTagsRuntime} converts i64 →
 * `__hashtable__*`.
 *
 * NestedJIT thin-AOT traps this helper avoids:
 * - `strlen()` / `isset($s[$i])` on `@file_get_contents` results are wrong — end when `$s[$i]===""`
 * - `substr($s, $strpos)` / `$i+$j` offsets miscompile — char state machines only
 * - PCRE / VmFs NestedJIT — `@file_get_contents` + literal scanners
 * - Keep scanners inline (private helpers / larger CFGs NestedJIT-OOM under thin init)
 *
 * Limitation: first `name="…"` / `content="…"` pair in the document (double-quoted). Enough
 * for php-src-shaped single-meta fixtures; multi-tag HTML is a follow-up.
 *
 * SSOT semantics: {@see VmMetaTags::getMetaTags()} / php-src php_meta_tags.c
 * php-src: ext/standard/php_meta_tags.c — PHP_FUNCTION(get_meta_tags)
 */
final class MetaTagsJitHelper
{
    /**
     * NestedJIT-safe native hashtable pointer (0 = false / read failure).
     */
    public static function getMetaTags(string $filename, bool $useIncludePath): int
    {
        if ($useIncludePath) {
            $resolved = VmFs::resolveIncludePath($filename);
            if (false !== $resolved) {
                $filename = $resolved;
            }
        }

        $html = @file_get_contents($filename);
        if (false === $html) {
            return 0;
        }

        $htPtr = (int) phpc_native_ht_alloc();
        if ($htPtr <= 0) {
            return 0;
        }

        $content = '';
        $st = 0;
        for ($i = 0; $i < 65536; ++$i) {
            $ch = $html[$i];
            if ('' === $ch) {
                break;
            }
            if (0 === $st) {
                $st = ('c' === $ch || 'C' === $ch) ? 1 : 0;
            } elseif (1 === $st) {
                $st = ('o' === $ch || 'O' === $ch) ? 2 : (('c' === $ch || 'C' === $ch) ? 1 : 0);
            } elseif (2 === $st) {
                $st = ('n' === $ch || 'N' === $ch) ? 3 : (('c' === $ch || 'C' === $ch) ? 1 : 0);
            } elseif (3 === $st) {
                $st = ('t' === $ch || 'T' === $ch) ? 4 : (('c' === $ch || 'C' === $ch) ? 1 : 0);
            } elseif (4 === $st) {
                $st = ('e' === $ch || 'E' === $ch) ? 5 : (('c' === $ch || 'C' === $ch) ? 1 : 0);
            } elseif (5 === $st) {
                $st = ('n' === $ch || 'N' === $ch) ? 6 : (('c' === $ch || 'C' === $ch) ? 1 : 0);
            } elseif (6 === $st) {
                $st = ('t' === $ch || 'T' === $ch) ? 7 : (('c' === $ch || 'C' === $ch) ? 1 : 0);
            } elseif (7 === $st) {
                $st = ('=' === $ch) ? 8 : (('c' === $ch || 'C' === $ch) ? 1 : 0);
            } elseif (8 === $st) {
                $st = ('"' === $ch) ? 9 : (('c' === $ch || 'C' === $ch) ? 1 : 0);
            } elseif (9 === $st) {
                if ('"' === $ch) {
                    break;
                }
                $content .= $ch;
            }
        }

        $name = '';
        $st = 0;
        for ($i = 0; $i < 65536; ++$i) {
            $ch = $html[$i];
            if ('' === $ch) {
                break;
            }
            if (0 === $st) {
                $st = ('n' === $ch || 'N' === $ch) ? 1 : 0;
            } elseif (1 === $st) {
                $st = ('a' === $ch || 'A' === $ch) ? 2 : (('n' === $ch || 'N' === $ch) ? 1 : 0);
            } elseif (2 === $st) {
                $st = ('m' === $ch || 'M' === $ch) ? 3 : (('n' === $ch || 'N' === $ch) ? 1 : 0);
            } elseif (3 === $st) {
                $st = ('e' === $ch || 'E' === $ch) ? 4 : (('n' === $ch || 'N' === $ch) ? 1 : 0);
            } elseif (4 === $st) {
                $st = ('=' === $ch) ? 5 : (('n' === $ch || 'N' === $ch) ? 1 : 0);
            } elseif (5 === $st) {
                $st = ('"' === $ch) ? 6 : (('n' === $ch || 'N' === $ch) ? 1 : 0);
            } elseif (6 === $st) {
                if ('"' === $ch) {
                    break;
                }
                $name .= $ch;
            }
        }

        if ('' !== $content && '' !== $name) {
            phpc_native_ht_set_string_key($htPtr, $name, $content);
        }

        return $htPtr;
    }
}
