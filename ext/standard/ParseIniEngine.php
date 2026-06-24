<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native INI parser — no host PHP \parse_ini_*() (issue #3263, php-src ext/standard/ini.c).
 *
 * v1: INI_SCANNER_NORMAL only; RAW/TYPED deferred.
 */
final class ParseIniEngine
{
    public const SCANNER_NORMAL = 0;
    public const SCANNER_RAW = 1;
    public const SCANNER_TYPED = 2;

    private static ?string $lastSyntaxError = null;
    private static ?int $lastSyntaxLine = null;

    public static function lastSyntaxError(): ?string
    {
        return self::$lastSyntaxError;
    }

    public static function lastSyntaxLine(): ?int
    {
        return self::$lastSyntaxLine;
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function parse(string $ini, bool $processSections = false, int $scannerMode = self::SCANNER_NORMAL): array|false
    {
        self::$lastSyntaxError = null;
        self::$lastSyntaxLine = null;
        if (self::SCANNER_NORMAL !== $scannerMode) {
            throw new \LogicException(
                'parse_ini_string(): only INI_SCANNER_NORMAL is supported in this compiler build'
            );
        }

        $result = [];
        $currentSection = null;
        $sectionData = [];

        foreach (self::splitLines($ini) as $lineNo => $line) {
            $line = self::trimWs($line);
            if ('' === $line) {
                continue;
            }
            if (';' === $line[0] || '#' === $line[0]) {
                continue;
            }
            if ('[' === $line[0]) {
                $sectionName = self::parseSectionHeader($line);
                if (null === $sectionName) {
                    self::setSyntaxError($lineNo + 1, "unexpected '='");

                    return false;
                }
                if ($processSections) {
                    if (!isset($result[$sectionName])) {
                        $result[$sectionName] = [];
                    }
                    $currentSection = $sectionName;
                } else {
                    $currentSection = null;
                }
                continue;
            }

            $eq = strpos($line, '=');
            if (false === $eq) {
                continue;
            }
            $key = self::trimWs(substr($line, 0, $eq));
            if ('' === $key) {
                self::setSyntaxError($lineNo + 1, "unexpected '='");

                return false;
            }
            $reservedToken = self::reservedKeySyntaxToken($key);
            if (null !== $reservedToken) {
                self::setSyntaxError($lineNo + 1, 'unexpected '.$reservedToken);

                return false;
            }
            $rawValue = substr($line, $eq + 1);
            $value = self::parseValue($rawValue);
            if (false === $value) {
                if (null === self::$lastSyntaxError) {
                    self::setSyntaxError($lineNo + 1, "unexpected '='");
                }

                return false;
            }

            if ($processSections && null !== $currentSection) {
                $result[$currentSection][$key] = $value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private static function setSyntaxError(int $line, string $detail): void
    {
        self::$lastSyntaxError = $detail;
        self::$lastSyntaxLine = $line;
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $ini): array
    {
        return preg_split('/\R/', $ini) ?: [];
    }

    private static function trimWs(string $value): string
    {
        return trim($value, " \t\r\n");
    }

    private static function parseSectionHeader(string $line): ?string
    {
        if (!str_ends_with($line, ']')) {
            return null;
        }
        $inner = substr($line, 1, -1);
        $inner = self::trimWs($inner);
        if ('' === $inner) {
            return null;
        }
        $colon = strpos($inner, ':');
        if (false !== $colon) {
            $inner = substr($inner, 0, $colon);
        }

        return self::trimWs($inner);
    }

    /**
     * @return string|false
     */
    private static function parseValue(string $raw): string|false
    {
        $raw = self::trimWs($raw);
        if ('' === $raw) {
            return '';
        }
        $first = $raw[0];
        if ('"' === $first) {
            return self::parseDoubleQuoted($raw);
        }
        if ("'" === $first) {
            return self::parseSingleQuoted($raw);
        }

        $semi = strpos($raw, ';');
        if (false !== $semi) {
            $raw = self::trimWs(substr($raw, 0, $semi));
        }
        $hash = strpos($raw, '#');
        if (false !== $hash) {
            $raw = self::trimWs(substr($raw, 0, $hash));
        }

        return self::normalizeUnquoted($raw);
    }

    /**
     * @return string|false
     */
    private static function parseDoubleQuoted(string $raw): string|false
    {
        if (!str_ends_with($raw, '"') || 1 === strlen($raw)) {
            return false;
        }
        $inner = substr($raw, 1, -1);
        $out = '';
        $len = strlen($inner);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $inner[$i];
            if ('\\' === $ch && $i + 1 < $len) {
                $out .= $inner[++$i];
                continue;
            }
            $out .= $ch;
        }

        return $out;
    }

    /**
     * @return string|false
     */
    private static function parseSingleQuoted(string $raw): string|false
    {
        if (!str_ends_with($raw, "'") || 1 === strlen($raw)) {
            return false;
        }

        return substr($raw, 1, -1);
    }

    /**
     * @return string|false
     */
    private static function normalizeUnquoted(string $raw): string|false
    {
        $lower = strtolower($raw);
        return match ($lower) {
            'null', 'none', '""', "''" => '',
            'yes', 'on', 'true' => '1',
            'no', 'off', 'false' => '',
            default => $raw,
        };
    }

    /**
     * INI_SCANNER_NORMAL rejects bool/null keyword keys (php-src ini.c ZEND_INI_PARSER).
     */
    private static function reservedKeySyntaxToken(string $key): ?string
    {
        return match (strtolower($key)) {
            'on', 'yes', 'true' => 'BOOL_TRUE',
            'off', 'no', 'false', 'none' => 'BOOL_FALSE',
            'null' => 'NULL_NULL',
            default => null,
        };
    }
}
