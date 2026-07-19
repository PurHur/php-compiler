<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Compile-time attribute table lookup for JIT/AOT reflection (#10086, php-in-PHP, #20901).
 *
 * SSOT over per-module JSON tables embedded at link time; replaces LLVM branch chains
 * in {@see \PHPCompiler\JIT\Builtin\AttributeRegistryLowering}.
 * NestedJIT-safe under thin AOT: string-scan JSON lists — no host decode builtin, no by-ref,
 * no nullable returns (IncludePath #20877 / Serialize #20773 shape).
 * php-src: Zend/zend_attributes.c — compile-time attribute tables (semantics only)
 */
final class AttributeRegistryJitHelper
{
    public static function classCount(string $classLc, string $classNamesJson): int
    {
        return self::countJsonStringList(self::findTopLevelStringList($classNamesJson, $classLc));
    }

    public static function classNameAt(string $classLc, int $idx, string $classNamesJson): string
    {
        return self::jsonStringAt(self::findTopLevelStringList($classNamesJson, $classLc), $idx);
    }

    public static function methodCount(string $classLc, string $methodLc, string $methodNamesJson): int
    {
        $classObj = self::findTopLevelObjectPayload($methodNamesJson, $classLc);
        if ('' === $classObj) {
            return 0;
        }

        return self::countJsonStringList(self::findTopLevelStringList('{'.$classObj.'}', $methodLc));
    }

    public static function methodNameAt(
        string $classLc,
        string $methodLc,
        int $idx,
        string $methodNamesJson
    ): string {
        $classObj = self::findTopLevelObjectPayload($methodNamesJson, $classLc);
        if ('' === $classObj) {
            return '';
        }

        return self::jsonStringAt(self::findTopLevelStringList('{'.$classObj.'}', $methodLc), $idx);
    }

    /** Inner of `[...]` for a top-level object key, or empty string when missing. */
    private static function findTopLevelStringList(string $json, string $keyLc): string
    {
        if ('' === $json || '{}' === $json) {
            return '';
        }
        $len = \strlen($json);
        $i = 0;
        while ($i < $len) {
            $parsed = self::tryParseJsonString($json, $i, $len);
            if ('' === $parsed) {
                $i = $i + 1;
                continue;
            }
            $pipe = \strpos($parsed, '|');
            if (false === $pipe) {
                $i = $i + 1;
                continue;
            }
            $key = \substr($parsed, 0, $pipe);
            $i = (int) \substr($parsed, $pipe + 1);
            $i = self::skipWs($json, $i, $len);
            if ($i >= $len || ':' !== $json[$i]) {
                continue;
            }
            $i = $i + 1;
            $i = self::skipWs($json, $i, $len);
            if ($i >= $len || '[' !== $json[$i]) {
                if ($i < $len && '{' === $json[$i]) {
                    $i = self::skipJsonValue($json, $i, $len);
                }
                continue;
            }
            $i = $i + 1;
            $start = $i;
            $depth = 1;
            while ($i < $len && $depth > 0) {
                $c = $json[$i];
                if ('"' === $c) {
                    $skip = self::tryParseJsonString($json, $i, $len);
                    if ('' !== $skip) {
                        $pipe2 = \strpos($skip, '|');
                        if (false !== $pipe2) {
                            $i = (int) \substr($skip, $pipe2 + 1);
                            continue;
                        }
                    }
                    $i = $i + 1;
                    continue;
                }
                if ('[' === $c) {
                    $depth = $depth + 1;
                } elseif (']' === $c) {
                    $depth = $depth - 1;
                    if (0 === $depth) {
                        $inner = \substr($json, $start, $i - $start);
                        $i = $i + 1;
                        if (0 === \strcasecmp($keyLc, $key)) {
                            return $inner;
                        }
                        break;
                    }
                }
                $i = $i + 1;
            }
        }

        return '';
    }

    /** Inner of `{...}` for a top-level object key, or empty string when missing. */
    private static function findTopLevelObjectPayload(string $json, string $keyLc): string
    {
        if ('' === $json || '{}' === $json) {
            return '';
        }
        $len = \strlen($json);
        $i = 0;
        while ($i < $len) {
            $parsed = self::tryParseJsonString($json, $i, $len);
            if ('' === $parsed) {
                $i = $i + 1;
                continue;
            }
            $pipe = \strpos($parsed, '|');
            if (false === $pipe) {
                $i = $i + 1;
                continue;
            }
            $key = \substr($parsed, 0, $pipe);
            $i = (int) \substr($parsed, $pipe + 1);
            $i = self::skipWs($json, $i, $len);
            if ($i >= $len || ':' !== $json[$i]) {
                continue;
            }
            $i = $i + 1;
            $i = self::skipWs($json, $i, $len);
            if ($i >= $len || '{' !== $json[$i]) {
                if ($i < $len && '[' === $json[$i]) {
                    $i = self::skipJsonValue($json, $i, $len);
                }
                continue;
            }
            $i = $i + 1;
            $start = $i;
            $depth = 1;
            while ($i < $len && $depth > 0) {
                $c = $json[$i];
                if ('"' === $c) {
                    $skip = self::tryParseJsonString($json, $i, $len);
                    if ('' !== $skip) {
                        $pipe2 = \strpos($skip, '|');
                        if (false !== $pipe2) {
                            $i = (int) \substr($skip, $pipe2 + 1);
                            continue;
                        }
                    }
                    $i = $i + 1;
                    continue;
                }
                if ('{' === $c) {
                    $depth = $depth + 1;
                } elseif ('}' === $c) {
                    $depth = $depth - 1;
                    if (0 === $depth) {
                        $inner = \substr($json, $start, $i - $start);
                        $i = $i + 1;
                        if (0 === \strcasecmp($keyLc, $key)) {
                            return $inner;
                        }
                        break;
                    }
                }
                $i = $i + 1;
            }
        }

        return '';
    }

    private static function countJsonStringList(string $listInner): int
    {
        if ('' === $listInner) {
            return 0;
        }
        $len = \strlen($listInner);
        $i = 0;
        $n = 0;
        while ($i < $len) {
            $parsed = self::tryParseJsonString($listInner, $i, $len);
            if ('' !== $parsed) {
                $pipe = \strpos($parsed, '|');
                if (false !== $pipe) {
                    $n = $n + 1;
                    $i = (int) \substr($parsed, $pipe + 1);
                    continue;
                }
            }
            $i = $i + 1;
        }

        return $n;
    }

    private static function jsonStringAt(string $listInner, int $idx): string
    {
        if ('' === $listInner || $idx < 0) {
            return '';
        }
        $len = \strlen($listInner);
        $i = 0;
        $n = 0;
        while ($i < $len) {
            $parsed = self::tryParseJsonString($listInner, $i, $len);
            if ('' !== $parsed) {
                $pipe = \strpos($parsed, '|');
                if (false !== $pipe) {
                    $s = \substr($parsed, 0, $pipe);
                    $i = (int) \substr($parsed, $pipe + 1);
                    if ($n === $idx) {
                        return $s;
                    }
                    $n = $n + 1;
                    continue;
                }
            }
            $i = $i + 1;
        }

        return '';
    }

    /**
     * When $json[$pos] is `"`, return "content|newPos"; otherwise return "" (pos unchanged).
     * Pipe separator avoids by-ref / nullable NestedJIT hazards (#20901).
     */
    private static function tryParseJsonString(string $json, int $pos, int $len): string
    {
        $i = self::skipWs($json, $pos, $len);
        if ($i >= $len || '"' !== $json[$i]) {
            return '';
        }
        $i = $i + 1;
        $out = '';
        while ($i < $len) {
            $c = $json[$i];
            if ('"' === $c) {
                $i = $i + 1;

                return $out.'|'.$i;
            }
            if ('\\' === $c && $i + 1 < $len) {
                $n = $json[$i + 1];
                if ('"' === $n || '\\' === $n || '/' === $n) {
                    $out = $out.$n;
                } elseif ('n' === $n) {
                    $out = $out."\n";
                } elseif ('t' === $n) {
                    $out = $out."\t";
                } else {
                    $out = $out.$n;
                }
                $i = $i + 2;
                continue;
            }
            $out = $out.$c;
            $i = $i + 1;
        }

        return $out.'|'.$i;
    }

    private static function skipWs(string $json, int $i, int $len): int
    {
        while ($i < $len) {
            $c = $json[$i];
            if (' ' !== $c && "\t" !== $c && "\n" !== $c && "\r" !== $c) {
                break;
            }
            $i = $i + 1;
        }

        return $i;
    }

    private static function skipJsonValue(string $json, int $i, int $len): int
    {
        $i = self::skipWs($json, $i, $len);
        if ($i >= $len) {
            return $i;
        }
        $c = $json[$i];
        if ('"' === $c) {
            $parsed = self::tryParseJsonString($json, $i, $len);
            if ('' !== $parsed) {
                $pipe = \strpos($parsed, '|');
                if (false !== $pipe) {
                    return (int) \substr($parsed, $pipe + 1);
                }
            }

            return $i + 1;
        }
        if ('{' === $c || '[' === $c) {
            $open = $c;
            $close = '{' === $c ? '}' : ']';
            $i = $i + 1;
            $depth = 1;
            while ($i < $len && $depth > 0) {
                $ch = $json[$i];
                if ('"' === $ch) {
                    $parsed = self::tryParseJsonString($json, $i, $len);
                    if ('' !== $parsed) {
                        $pipe = \strpos($parsed, '|');
                        if (false !== $pipe) {
                            $i = (int) \substr($parsed, $pipe + 1);
                            continue;
                        }
                    }
                    $i = $i + 1;
                    continue;
                }
                if ($ch === $open) {
                    $depth = $depth + 1;
                } elseif ($ch === $close) {
                    $depth = $depth - 1;
                }
                $i = $i + 1;
            }

            return $i;
        }
        while ($i < $len) {
            $ch = $json[$i];
            if (',' === $ch || '}' === $ch || ']' === $ch) {
                break;
            }
            $i = $i + 1;
        }

        return $i;
    }
}
