<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Compile-time attribute table lookup for JIT/AOT reflection (#10086, php-in-PHP, #20901).
 *
 * SSOT over per-module JSON tables embedded at link time; replaces LLVM branch chains
 * in {@see \PHPCompiler\JIT\Builtin\AttributeRegistryLowering}.
 * NestedJIT-safe under thin AOT: string-scan JSON lists — no host decode builtin / foreach-array
 * (IncludePath #20877 / Serialize #20773 shape).
 * php-src: Zend/zend_attributes.c — compile-time attribute tables (semantics only)
 */
final class AttributeRegistryJitHelper
{
    public static function classCount(string $classLc, string $classNamesJson): int
    {
        $list = self::findTopLevelStringList($classNamesJson, $classLc);

        return self::countJsonStringList($list);
    }

    public static function classNameAt(string $classLc, int $idx, string $classNamesJson): string
    {
        $list = self::findTopLevelStringList($classNamesJson, $classLc);

        return self::jsonStringAt($list, $idx);
    }

    public static function methodCount(string $classLc, string $methodLc, string $methodNamesJson): int
    {
        $classObj = self::findTopLevelObjectPayload($methodNamesJson, $classLc);
        if ('' === $classObj) {
            return 0;
        }
        $list = self::findTopLevelStringList('{'.$classObj.'}', $methodLc);

        return self::countJsonStringList($list);
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
        $list = self::findTopLevelStringList('{'.$classObj.'}', $methodLc);

        return self::jsonStringAt($list, $idx);
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
            $key = self::scanJsonString($json, $i, $len);
            if (null === $key) {
                ++$i;
                continue;
            }
            $i = self::skipWs($json, $i, $len);
            if ($i >= $len || ':' !== $json[$i]) {
                continue;
            }
            ++$i;
            $i = self::skipWs($json, $i, $len);
            if ($i >= $len || '[' !== $json[$i]) {
                if ($i < $len && '{' === $json[$i]) {
                    $i = self::skipJsonValue($json, $i, $len);
                }
                continue;
            }
            ++$i;
            $start = $i;
            $depth = 1;
            while ($i < $len && $depth > 0) {
                $c = $json[$i];
                if ('"' === $c) {
                    self::scanJsonString($json, $i, $len);
                    continue;
                }
                if ('[' === $c) {
                    ++$depth;
                } elseif (']' === $c) {
                    --$depth;
                    if (0 === $depth) {
                        $inner = \substr($json, $start, $i - $start);
                        ++$i;
                        if (0 === \strcasecmp($keyLc, $key)) {
                            return $inner;
                        }
                        break;
                    }
                }
                ++$i;
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
            $key = self::scanJsonString($json, $i, $len);
            if (null === $key) {
                ++$i;
                continue;
            }
            $i = self::skipWs($json, $i, $len);
            if ($i >= $len || ':' !== $json[$i]) {
                continue;
            }
            ++$i;
            $i = self::skipWs($json, $i, $len);
            if ($i >= $len || '{' !== $json[$i]) {
                if ($i < $len && '[' === $json[$i]) {
                    $i = self::skipJsonValue($json, $i, $len);
                }
                continue;
            }
            ++$i;
            $start = $i;
            $depth = 1;
            while ($i < $len && $depth > 0) {
                $c = $json[$i];
                if ('"' === $c) {
                    self::scanJsonString($json, $i, $len);
                    continue;
                }
                if ('{' === $c) {
                    ++$depth;
                } elseif ('}' === $c) {
                    --$depth;
                    if (0 === $depth) {
                        $inner = \substr($json, $start, $i - $start);
                        ++$i;
                        if (0 === \strcasecmp($keyLc, $key)) {
                            return $inner;
                        }
                        break;
                    }
                }
                ++$i;
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
            $s = self::scanJsonString($listInner, $i, $len);
            if (null !== $s) {
                ++$n;
                continue;
            }
            ++$i;
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
            $s = self::scanJsonString($listInner, $i, $len);
            if (null !== $s) {
                if ($n === $idx) {
                    return $s;
                }
                ++$n;
                continue;
            }
            ++$i;
        }

        return '';
    }

    /**
     * If $json[$i] is `"`, advance $i past the string and return decoded content.
     * Otherwise leave $i unchanged and return null.
     *
     * @param-out int $i
     */
    private static function scanJsonString(string $json, int &$i, int $len): ?string
    {
        $i = self::skipWs($json, $i, $len);
        if ($i >= $len || '"' !== $json[$i]) {
            return null;
        }
        ++$i;
        $out = '';
        while ($i < $len) {
            $c = $json[$i];
            if ('"' === $c) {
                ++$i;

                return $out;
            }
            if ('\\' === $c && $i + 1 < $len) {
                $n = $json[$i + 1];
                if ('"' === $n || '\\' === $n || '/' === $n) {
                    $out .= $n;
                } elseif ('n' === $n) {
                    $out .= "\n";
                } elseif ('t' === $n) {
                    $out .= "\t";
                } else {
                    $out .= $n;
                }
                $i += 2;
                continue;
            }
            $out .= $c;
            ++$i;
        }

        return $out;
    }

    private static function skipWs(string $json, int $i, int $len): int
    {
        while ($i < $len) {
            $c = $json[$i];
            if (' ' !== $c && "\t" !== $c && "\n" !== $c && "\r" !== $c) {
                break;
            }
            ++$i;
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
            self::scanJsonString($json, $i, $len);

            return $i;
        }
        if ('{' === $c || '[' === $c) {
            $open = $c;
            $close = '{' === $c ? '}' : ']';
            ++$i;
            $depth = 1;
            while ($i < $len && $depth > 0) {
                $ch = $json[$i];
                if ('"' === $ch) {
                    self::scanJsonString($json, $i, $len);
                    continue;
                }
                if ($ch === $open) {
                    ++$depth;
                } elseif ($ch === $close) {
                    --$depth;
                }
                ++$i;
            }

            return $i;
        }
        while ($i < $len) {
            $ch = $json[$i];
            if (',' === $ch || '}' === $ch || ']' === $ch) {
                break;
            }
            ++$i;
        }

        return $i;
    }
}
