<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native INI parser — no host PHP \parse_ini_*() (issue #3263, php-src ext/standard/ini.c).
 *
 * INI_SCANNER_NORMAL, RAW, and TYPED (issue #9153 / php-src ext/standard/ini.c).
 */
final class ParseIniEngine
{
    public const SCANNER_NORMAL = 0;
    public const SCANNER_RAW = 1;
    public const SCANNER_TYPED = 2;

    /** php-src zend_ini_scanner.l — unterminated double-quoted value (#29358). */
    private const UNTERMINATED_DOUBLE_QUOTE =
        'unexpected end of file, expecting TC_DOLLAR_CURLY or TC_QUOTED_STRING or \'"\'';

    /** php-src zend_ini_scanner.l — unterminated single-quoted value. */
    private const UNTERMINATED_SINGLE_QUOTE = 'unexpected end of file';

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
        if (!\in_array($scannerMode, [self::SCANNER_NORMAL, self::SCANNER_RAW, self::SCANNER_TYPED], true)) {
            return false;
        }

        $result = [];
        $currentSection = null;
        $sectionData = [];

        $lines = self::splitLines($ini);
        $lineCount = \count($lines);
        for ($lineNo = 0; $lineNo < $lineCount; ++$lineNo) {
            $line = self::trimWs($lines[$lineNo]);
            if ('' === $line) {
                continue;
            }
            if (';' === $line[0] || '#' === $line[0]) {
                continue;
            }
            if ('[' === $line[0]) {
                if (!str_ends_with($line, ']')) {
                    self::setSyntaxError($lineNo + 1, "unexpected end of file, expecting ']'");

                    return false;
                }
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
            $parsedValue = self::parseValueFromLines($lines, $lineNo, $rawValue, $scannerMode);
            if (false === $parsedValue) {
                if (null === self::$lastSyntaxError) {
                    self::setSyntaxError($lineNo + 1, "unexpected '='");
                } elseif (null === self::$lastSyntaxLine) {
                    self::$lastSyntaxLine = $lineNo + 1;
                }

                return false;
            }
            $value = self::finalizeValue($parsedValue, $scannerMode);

            if ($processSections && null !== $currentSection) {
                self::assignKeyValue($result[$currentSection], $key, $value);
            } else {
                self::assignKeyValue($result, $key, $value);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function assignKeyValue(array &$target, string $key, mixed $value): void
    {
        if (str_ends_with($key, '[]')) {
            $baseKey = substr($key, 0, -2);
            if (!isset($target[$baseKey]) || !\is_array($target[$baseKey])) {
                $target[$baseKey] = [$value];

                return;
            }
            $target[$baseKey][] = $value;

            return;
        }
        $target[$key] = $value;
    }

    private static function finalizeValue(string $raw, int $scannerMode): mixed
    {
        return match ($scannerMode) {
            self::SCANNER_RAW => $raw,
            self::SCANNER_TYPED => self::coerceTypedValue($raw),
            default => self::normalizeUnquoted($raw),
        };
    }

    /**
     * INI_SCANNER_TYPED — zend_ini_parse typed coercion (php-src ext/standard/ini.c).
     */
    private static function coerceTypedValue(string $raw): mixed
    {
        $lower = strtolower($raw);
        return match ($lower) {
            'null' => null,
            'yes', 'on', 'true' => true,
            'no', 'off', 'false', 'none' => false,
            default => self::coerceNumericOrString($raw),
        };
    }

    private static function coerceNumericOrString(string $raw): mixed
    {
        if ('' === $raw) {
            return '';
        }
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return $raw;
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
     * @param list<string> $lines
     *
     * @return string|false
     */
    private static function parseValueFromLines(array $lines, int &$lineNo, string $raw, int $scannerMode): string|false
    {
        $raw = self::trimWs($raw);
        if ('' === $raw) {
            return '';
        }
        if ('"' === $raw[0] && !self::doubleQuotedIsComplete($raw)) {
            $combined = $raw;
            $lineCount = \count($lines);
            $startLine = $lineNo + 1;
            while (!self::doubleQuotedIsComplete($combined)) {
                ++$lineNo;
                if ($lineNo >= $lineCount) {
                    // Zend reports the last line scanned (EOF), not the opening line (#29358).
                    self::setSyntaxError(
                        \max($startLine, $lineCount),
                        self::UNTERMINATED_DOUBLE_QUOTE
                    );

                    return false;
                }
                $combined .= "\n".$lines[$lineNo];
            }

            return self::parseDoubleQuoted(self::trimWs($combined), $scannerMode);
        }

        return self::parseValue($raw, $scannerMode);
    }

    private static function doubleQuotedIsComplete(string $raw): bool
    {
        $raw = self::trimWs($raw);
        if ('"' !== $raw[0]) {
            return true;
        }
        $len = strlen($raw);
        for ($i = 1; $i < $len; ++$i) {
            if ('\\' === $raw[$i]) {
                ++$i;
                continue;
            }
            if ('"' === $raw[$i]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|false
     */
    private static function parseValue(string $raw, int $scannerMode): string|false
    {
        $raw = self::trimWs($raw);
        if ('' === $raw) {
            return '';
        }
        $first = $raw[0];
        if ('"' === $first) {
            return self::parseDoubleQuoted($raw, $scannerMode);
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

        // Unquoted ${ENV} expands under NORMAL/TYPED only (php-src zend_ini_scanner.l / #23564).
        if (self::SCANNER_RAW === $scannerMode) {
            return $raw;
        }

        return self::expandEnvInString($raw);
    }

    /**
     * Substitute ${ENV} / ${ENV:-fallback} spans in an unquoted INI value.
     */
    private static function expandEnvInString(string $raw): string
    {
        $out = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $raw[$i];
            if ('$' === $ch && $i + 1 < $len && '{' === $raw[$i + 1]) {
                $expanded = self::expandEnvInterpolation($raw, $i);
                if (null === $expanded) {
                    $out .= '$';
                    continue;
                }
                [$value, $nextIndex] = $expanded;
                $out .= $value;
                $i = $nextIndex;
                continue;
            }
            $out .= $ch;
        }

        return $out;
    }

    /**
     * @return string|false
     */
    private static function parseDoubleQuoted(string $raw, int $scannerMode): string|false
    {
        if (!str_ends_with($raw, '"') || 1 === strlen($raw)) {
            // Multiline EOF path sets line+detail; this is a same-line fallback (#29358).
            if (null === self::$lastSyntaxError) {
                self::$lastSyntaxError = self::UNTERMINATED_DOUBLE_QUOTE;
            }

            return false;
        }
        $inner = substr($raw, 1, -1);
        $out = '';
        $len = strlen($inner);
        // ZEND_INI_SCANNER_RAW skips ${ENV} expansion (php-src zend_ini_scanner.l / #23563).
        $expandEnv = self::SCANNER_RAW !== $scannerMode;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $inner[$i];
            if ('\\' === $ch && $i + 1 < $len) {
                $out .= $inner[++$i];
                continue;
            }
            if ($expandEnv && '$' === $ch && $i + 1 < $len && '{' === $inner[$i + 1]) {
                $expanded = self::expandEnvInterpolation($inner, $i);
                if (null === $expanded) {
                    $out .= '$';
                    continue;
                }
                [$value, $nextIndex] = $expanded;
                $out .= $value;
                $i = $nextIndex;
                continue;
            }
            $out .= $ch;
        }

        return $out;
    }

    /**
     * php-src ext/standard/ini.c — ${ENV} substitution in double-quoted INI values
     * under NORMAL/TYPED (not RAW — #23563).
     *
     * @return array{0: string, 1: int}|null
     */
    private static function expandEnvInterpolation(string $inner, int $start): ?array
    {
        if ('$' !== $inner[$start] || $start + 1 >= strlen($inner) || '{' !== $inner[$start + 1]) {
            return null;
        }
        $close = strpos($inner, '}', $start + 2);
        if (false === $close) {
            return null;
        }
        $token = substr($inner, $start + 2, $close - $start - 2);
        $fallback = null;
        $colonFallback = strpos($token, ':-');
        if (false !== $colonFallback) {
            $envName = substr($token, 0, $colonFallback);
            $fallback = substr($token, $colonFallback + 2);
        } else {
            $envName = $token;
        }
        if ('' === $envName || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $envName)) {
            return null;
        }
        $resolved = VmEnv::getenv($envName);
        if (false === $resolved) {
            $resolved = null !== $fallback ? $fallback : '';
        }

        return [$resolved, $close];
    }

    /**
     * @return string|false
     */
    private static function parseSingleQuoted(string $raw): string|false
    {
        if (!str_ends_with($raw, "'") || 1 === strlen($raw)) {
            if (null === self::$lastSyntaxError) {
                self::$lastSyntaxError = self::UNTERMINATED_SINGLE_QUOTE;
            }

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
