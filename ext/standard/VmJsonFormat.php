<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Native json_encode()/json_decode() for exported PHP data — no host ext/json (#4795).
 *
 * php-src ref: ext/json/php_json.c, json_encoder.c, json_parser.c
 */
final class VmJsonFormat
{
    /**
     * @param array<mixed>|bool|float|int|null|string $exported VmJson::export shape
     */
    public static function encodeExported(mixed $exported, int $flags = 0, int $maxDepth = 512): string|false
    {
        VmJson::setLastError(0);
        try {
            return self::encodeValue($exported, $flags, 0, $maxDepth);
        } catch (VmJsonExportException $e) {
            VmJson::setLastError($e->errorCode);
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \JsonException(VmJson::lastErrorMsg(), $e->errorCode);
            }

            return false;
        } catch (\Throwable) {
            VmJson::setLastError(VmJson::ERROR_UNSUPPORTED_TYPE);
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \JsonException(VmJson::lastErrorMsg(), VmJson::ERROR_UNSUPPORTED_TYPE);
            }

            return false;
        }
    }

    /**
     * @return array<mixed>|bool|float|int|null|string|\stdClass
     */
    public static function decode(string $json, bool $assoc = false, int $maxDepth = 512, int $flags = 0): mixed
    {
        VmJson::setLastError(0);
        if (!VmJsonFlags::ignoreInvalidUtf8($flags) && !VmJsonUtf8::isValidUtf8($json)) {
            VmJson::setLastError(5);
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \JsonException(VmJson::lastErrorMsg(), 5);
            }

            return null;
        }
        $parser = new VmJsonParser($json, $maxDepth, $assoc);
        $value = $parser->parseTop();
        if (VmJson::lastError() !== 0) {
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \JsonException(VmJson::lastErrorMsg(), VmJson::lastError());
            }

            return null;
        }
        if (!$parser->atEnd()) {
            VmJson::setLastError(4);
            if (VmJsonFlags::throwsOnError($flags)) {
                throw new \JsonException(VmJson::lastErrorMsg(), 4);
            }

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed>|bool|float|int|null|string $value
     */
    private static function encodeValue(mixed $value, int $flags, int $depth, int $maxDepth): string
    {
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            return self::encodeFloat($value, $flags);
        }
        if (\is_string($value)) {
            return self::encodeStringValue($value, $flags);
        }
        if ($value instanceof \stdClass) {
            $nestedDepth = $depth + 1;
            if ($nestedDepth > $maxDepth) {
                throw new VmJsonExportException(VmJson::ERROR_DEPTH);
            }
            $props = get_object_vars($value);
            if ([] === $props) {
                return self::wrapObject('{}', $flags, $depth);
            }
            $parts = [];
            foreach ($props as $key => $item) {
                if (!\is_string($key) && !\is_int($key)) {
                    throw new \LogicException('json_encode() only supports string or integer keys in this compiler build');
                }
                $keyStr = \is_int($key) ? (string) $key : $key;
                $parts[] = '"'.self::escapeString($keyStr, $flags).'"'.self::keyValueSeparator($flags)
                    .self::encodePairValue($item, $flags, $nestedDepth, $maxDepth);
            }

            return self::wrapObject('{'.self::joinParts($parts, $flags, $depth).'}', $flags, $depth);
        }
        if (\is_array($value)) {
            $nestedDepth = $depth + 1;
            if ($nestedDepth > $maxDepth) {
                throw new VmJsonExportException(VmJson::ERROR_DEPTH);
            }
            $encodeAsObject = !array_is_list($value) || self::forceObject($flags);
            if ([] === $value) {
                $empty = $encodeAsObject ? '{}' : '[]';

                return self::wrapContainer($empty, $flags, $depth);
            }
            if (!$encodeAsObject) {
                $parts = [];
                foreach ($value as $item) {
                    $parts[] = self::encodePairValue($item, $flags, $nestedDepth, $maxDepth);
                }

                return self::wrapContainer('['.self::joinParts($parts, $flags, $depth).']', $flags, $depth);
            }
            $parts = [];
            foreach ($value as $key => $item) {
                if (!\is_string($key) && !\is_int($key)) {
                    throw new \LogicException(
                        'json_encode() only supports string or integer keys in this compiler build'
                    );
                }
                $keyStr = \is_int($key) ? (string) $key : $key;
                $parts[] = '"'.self::escapeString($keyStr, $flags).'"'.self::keyValueSeparator($flags)
                    .self::encodePairValue($item, $flags, $nestedDepth, $maxDepth);
            }

            return self::wrapObject('{'.self::joinParts($parts, $flags, $depth).'}', $flags, $depth);
        }

        throw new \LogicException('json_encode() unsupported exported type in this compiler build');
    }

    /**
     * @param list<string> $parts
     */
    private static function joinParts(array $parts, int $flags, int $depth): string
    {
        if (0 === ($flags & VmJsonFlags::PRETTY_PRINT)) {
            return \implode(',', $parts);
        }
        $indent = self::indent($depth + 1);
        $inner = \implode(','."\n".$indent, $parts);

        return "\n".$indent.$inner."\n".self::indent($depth);
    }

    private static function wrapObject(string $body, int $flags, int $depth): string
    {
        if (0 === ($flags & VmJsonFlags::PRETTY_PRINT) || '{}' === $body) {
            return $body;
        }

        return $body;
    }

    private static function wrapContainer(string $body, int $flags, int $depth): string
    {
        return self::wrapObject($body, $flags, $depth);
    }

    /**
     * @param array<mixed>|bool|float|int|null|string $value
     */
    private static function encodePairValue(mixed $value, int $flags, int $depth, int $maxDepth): string
    {
        return self::encodeValue($value, $flags, $depth, $maxDepth);
    }

    private static function indent(int $depth): string
    {
        return \str_repeat('    ', $depth);
    }

    private static function keyValueSeparator(int $flags): string
    {
        return 0 !== ($flags & VmJsonFlags::PRETTY_PRINT) ? ': ' : ':';
    }

    private static function forceObject(int $flags): bool
    {
        return 0 !== ($flags & VmJsonFlags::FORCE_OBJECT);
    }

    private static function encodeStringValue(string $value, int $flags): string
    {
        if (0 !== ($flags & VmJsonFlags::NUMERIC_CHECK)) {
            $numeric = self::tryEncodeNumericStringValue($value, $flags);
            if (null !== $numeric) {
                return $numeric;
            }
        }

        return '"'.self::escapeString($value, $flags).'"';
    }

    /**
     * php-src: ext/json/php_json_encoder.c — php_json_is_numeric_string / is_numeric_string.
     */
    private static function tryEncodeNumericStringValue(string $value, int $flags): ?string
    {
        if ('' === $value || !is_numeric($value)) {
            return null;
        }
        if (!str_contains($value, '.') && !str_contains(strtolower($value), 'e')) {
            return (string) (int) $value;
        }
        $num = (float) $value;
        if (is_nan($num) || is_infinite($num)) {
            return null;
        }
        if ((float) (int) $num === $num && abs($num) < 1.0e15) {
            return (string) (int) $num;
        }

        return self::encodeFloat($num, $flags);
    }

    /**
     * php-src ext/json/php_json_encoder.c — php_json_encode_double / zend_gcvt dtoa.
     */
    private static function encodeFloat(float $num, int $flags): string
    {
        if (is_nan($num) || is_infinite($num)) {
            if (VmJsonFlags::partialOutputOnError($flags)) {
                VmJson::setLastError(VmJson::ERROR_INF_OR_NAN);

                return '0';
            }
            throw new VmJsonExportException(VmJson::ERROR_INF_OR_NAN);
        }
        $preserveZero = 0 !== ($flags & VmJsonFlags::PRESERVE_ZERO_FRACTION);
        $isWhole = (float) (int) $num === $num && abs($num) < 1.0e15;
        if ($isWhole && !$preserveZero) {
            if (self::isNegativeZero($num)) {
                return '-0';
            }

            return (string) (int) $num;
        }

        $text = VmFloatDtoa::formatH($num);
        if ($preserveZero && $isWhole && !self::hasDecimalOrExponent($text)) {
            $text .= '.0';
        }

        return $text;
    }

    private static function hasDecimalOrExponent(string $text): bool
    {
        return str_contains($text, '.') || str_contains($text, 'E') || str_contains($text, 'e');
    }

    /** php-src ext/json/php_json_encoder.c — preserve IEEE754 negative zero sign. */
    private static function isNegativeZero(float $num): bool
    {
        return 0.0 == $num && 0.0 !== \atan2(0.0, $num);
    }

    private static function escapeString(string $value, int $flags): string
    {
        $unescapedUnicode = 0 !== ($flags & VmJsonFlags::UNESCAPED_UNICODE);
        $unescapedSlashes = 0 !== ($flags & VmJsonFlags::UNESCAPED_SLASHES);
        $out = '';
        $len = \strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            $ord = \ord($c);
            if ($unescapedUnicode && $ord >= 0x80) {
                $run = self::utf8RunLength($value, $i);
                if ($run > 0) {
                    $out .= \substr($value, $i, $run);
                    $i += $run - 1;

                    continue;
                }
            }
            if (!$unescapedUnicode && $ord >= 0x80) {
                $decoded = self::utf8CodePointAt($value, $i);
                if (null !== $decoded) {
                    [$cp, $run] = $decoded;
                    $out .= self::escapeUnicodeCodePoint($cp);
                    $i += $run - 1;

                    continue;
                }
            }
            $hexTag = 0 !== ($flags & VmJsonFlags::HEX_TAG);
            $hexAmp = 0 !== ($flags & VmJsonFlags::HEX_AMP);
            $hexApos = 0 !== ($flags & VmJsonFlags::HEX_APOS);
            $hexQuot = 0 !== ($flags & VmJsonFlags::HEX_QUOT);
            $out .= match ($c) {
                '<' => $hexTag ? '\\u003C' : '<',
                '>' => $hexTag ? '\\u003E' : '>',
                '&' => $hexAmp ? '\\u0026' : '&',
                "'" => $hexApos ? '\\u0027' : "'",
                '"' => $hexQuot ? '\\u0022' : '\\"',
                '\\' => '\\\\',
                '/' => $unescapedSlashes ? '/' : '\\/',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                "\f" => '\\f',
                "\b" => '\\b',
                default => $ord < 0x20
                    ? sprintf('\\u%04x', $ord)
                    : $c,
            };
        }

        return $out;
    }

    private static function utf8RunLength(string $value, int $offset): int
    {
        $len = \strlen($value);
        if ($offset >= $len) {
            return 0;
        }
        $b0 = \ord($value[$offset]);
        if ($b0 < 0x80) {
            return 0;
        }
        if (($b0 & 0xE0) === 0xC0) {
            $need = 2;
        } elseif (($b0 & 0xF0) === 0xE0) {
            $need = 3;
        } elseif (($b0 & 0xF8) === 0xF0) {
            $need = 4;
        } else {
            return 0;
        }
        if ($offset + $need > $len) {
            return 0;
        }
        for ($j = 1; $j < $need; $j++) {
            if ((\ord($value[$offset + $j]) & 0xC0) !== 0x80) {
                return 0;
            }
        }

        return $need;
    }

    /** @return array{0: int, 1: int}|null Unicode code point and UTF-8 byte length. */
    private static function utf8CodePointAt(string $value, int $offset): ?array
    {
        $run = self::utf8RunLength($value, $offset);
        if ($run <= 0) {
            return null;
        }
        $b0 = \ord($value[$offset]);
        $cp = match ($run) {
            2 => (($b0 & 0x1F) << 6) | (\ord($value[$offset + 1]) & 0x3F),
            3 => (($b0 & 0x0F) << 12)
                | ((\ord($value[$offset + 1]) & 0x3F) << 6)
                | (\ord($value[$offset + 2]) & 0x3F),
            4 => (($b0 & 0x07) << 18)
                | ((\ord($value[$offset + 1]) & 0x3F) << 12)
                | ((\ord($value[$offset + 2]) & 0x3F) << 6)
                | (\ord($value[$offset + 3]) & 0x3F),
            default => null,
        };
        if (null === $cp || $cp > 0x10FFFF) {
            return null;
        }

        return [$cp, $run];
    }

    private static function escapeUnicodeCodePoint(int $cp): string
    {
        if ($cp <= 0xFFFF) {
            return \sprintf('\\u%04x', $cp);
        }
        $cp -= 0x10000;
        $high = 0xD800 | ($cp >> 10);
        $low = 0xDC00 | ($cp & 0x3FF);

        return \sprintf('\\u%04x\\u%04x', $high, $low);
    }
}
