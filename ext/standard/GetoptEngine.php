<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * getopt() parser (ext/standard/php_getopt.c parity, issue #3251).
 */
final class GetoptEngine
{
    private const ARG_NONE = 0;
    private const ARG_REQUIRED = 1;
    private const ARG_OPTIONAL = 2;

    /**
     * @param list<string> $argv SAPI argv including script name at index 0
     * @param list<string> $longOptions
     *
     * @return array|false
     */
    public static function parse(
        array $argv,
        string $shortOptions,
        array $longOptions,
        ?int &$restIndex = null,
        bool $trackRestIndex = false
    ): array|false {
        $shortMap = self::parseShortOptions($shortOptions);
        if (null === $shortMap) {
            return false;
        }
        $longMap = self::parseLongOptions($longOptions);

        $argc = \count($argv);
        $result = [];
        $pos = 1;
        while ($pos < $argc) {
            $arg = $argv[$pos];
            if ('--' === $arg) {
                ++$pos;
                break;
            }
            if (str_starts_with($arg, '--') && \strlen($arg) > 2) {
                $spec = substr($arg, 2);
                $eq = strpos($spec, '=');
                $optName = false !== $eq ? substr($spec, 0, $eq) : $spec;
                $optValue = false !== $eq ? substr($spec, $eq + 1) : null;
                if (!isset($longMap[$optName])) {
                    break;
                }
                $argType = $longMap[$optName];
                if (self::ARG_NONE === $argType) {
                    if (null !== $optValue) {
                        break;
                    }
                    self::addResult($result, $optName, false);
                    ++$pos;
                    continue;
                }
                if (self::ARG_REQUIRED === $argType) {
                    if (null !== $optValue) {
                        self::addResult($result, $optName, $optValue);
                        ++$pos;
                        continue;
                    }
                    if ($pos + 1 >= $argc) {
                        break;
                    }
                    self::addResult($result, $optName, $argv[$pos + 1]);
                    $pos += 2;
                    continue;
                }
                // Optional long argument.
                if (null !== $optValue) {
                    self::addResult($result, $optName, $optValue);
                } elseif ($pos + 1 < $argc && !str_starts_with($argv[$pos + 1], '-')) {
                    self::addResult($result, $optName, $argv[$pos + 1]);
                    $pos += 2;
                } else {
                    self::addResult($result, $optName, false);
                    ++$pos;
                }
                continue;
            }
            if (str_starts_with($arg, '-') && \strlen($arg) > 1) {
                $chars = substr($arg, 1);
                $len = \strlen($chars);
                for ($i = 0; $i < $len; ++$i) {
                    $ch = $chars[$i];
                    if (!isset($shortMap[$ch])) {
                        if (0 === \count($result)) {
                            return [];
                        }
                        if ($trackRestIndex) {
                            $restIndex = $pos;
                        }

                        return $result;
                    }
                    $argType = $shortMap[$ch];
                    if (self::ARG_NONE === $argType) {
                        self::addResult($result, $ch, false);
                        continue;
                    }
                    $inline = substr($chars, $i + 1);
                    if ('' !== $inline) {
                        self::addResult($result, $ch, $inline);
                        ++$pos;
                        continue 2;
                    }
                    if ($pos + 1 >= $argc) {
                        if (self::ARG_OPTIONAL === $argType) {
                            self::addResult($result, $ch, false);
                            ++$pos;
                            continue 2;
                        }
                        if ($trackRestIndex) {
                            $restIndex = $pos;
                        }

                        return $result;
                    }
                    self::addResult($result, $ch, $argv[$pos + 1]);
                    $pos += 2;
                    continue 2;
                }
                ++$pos;
                continue;
            }
            break;
        }

        if ($trackRestIndex) {
            $restIndex = $pos;
        }

        return $result;
    }

    /**
     * @return array<string, int>|null
     */
    private static function parseShortOptions(string $shortOptions): ?array
    {
        $map = [];
        $len = \strlen($shortOptions);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $shortOptions[$i];
            if (!ctype_alnum($ch)) {
                if (':' === $ch) {
                    if (0 === $i || !isset($map[$shortOptions[$i - 1]])) {
                        return null;
                    }
                    if ($i + 1 < $len && ':' === $shortOptions[$i + 1]) {
                        $map[$shortOptions[$i - 1]] = self::ARG_OPTIONAL;
                        ++$i;
                    } else {
                        $map[$shortOptions[$i - 1]] = self::ARG_REQUIRED;
                    }
                    continue;
                }

                return null;
            }
            if (!isset($map[$ch])) {
                $map[$ch] = self::ARG_NONE;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $longOptions
     *
     * @return array<string, int>
     */
    private static function parseLongOptions(array $longOptions): array
    {
        $map = [];
        foreach ($longOptions as $spec) {
            $argType = self::ARG_NONE;
            if (str_ends_with($spec, '::')) {
                $argType = self::ARG_OPTIONAL;
                $spec = substr($spec, 0, -2);
            } elseif (str_ends_with($spec, ':')) {
                $argType = self::ARG_REQUIRED;
                $spec = substr($spec, 0, -1);
            }
            $map[$spec] = $argType;
        }

        return $map;
    }

    /**
     * @param array $result
     */
    private static function addResult(array &$result, string $name, bool|string $value): void
    {
        if (!\array_key_exists($name, $result)) {
            $result[$name] = $value;

            return;
        }
        $existing = $result[$name];
        if (\is_array($existing)) {
            $result[$name][] = $value;

            return;
        }
        $result[$name] = [$existing, $value];
    }
}
