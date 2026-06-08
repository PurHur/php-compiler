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
    public static function encodeExported(mixed $exported): string|false
    {
        VmJson::setLastError(0);
        try {
            return self::encodeValue($exported);
        } catch (\Throwable) {
            VmJson::setLastError(VmJson::ERROR_UNSUPPORTED_TYPE);

            return false;
        }
    }

    /**
     * @return array<mixed>|bool|float|int|null|string|\stdClass
     */
    public static function decode(string $json, bool $assoc = false, int $maxDepth = 512): mixed
    {
        VmJson::setLastError(0);
        $parser = new VmJsonParser($json, $maxDepth, $assoc);
        $value = $parser->parseTop();
        if (null === $value && VmJson::lastError() !== 0) {
            return null;
        }
        if (!$parser->atEnd()) {
            VmJson::setLastError(4);

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed>|bool|float|int|null|string $value
     */
    private static function encodeValue(mixed $value): string
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
            return self::encodeFloat($value);
        }
        if (\is_string($value)) {
            return '"'.self::escapeString($value).'"';
        }
        if ($value instanceof \stdClass) {
            $props = get_object_vars($value);
            if ([] === $props) {
                return '{}';
            }
            $parts = [];
            foreach ($props as $key => $item) {
                if (!\is_string($key)) {
                    throw new \LogicException('json_encode() only supports string keys in this compiler build');
                }
                $parts[] = '"'.self::escapeString($key).'":'.self::encodeValue($item);
            }

            return '{'.implode(',', $parts).'}';
        }
        if (\is_array($value)) {
            if ([] === $value) {
                return array_is_list($value) ? '[]' : '{}';
            }
            if (array_is_list($value)) {
                $parts = [];
                foreach ($value as $item) {
                    $parts[] = self::encodeValue($item);
                }

                return '['.\implode(',', $parts).']';
            }
            $parts = [];
            foreach ($value as $key => $item) {
                if (!\is_string($key)) {
                    throw new \LogicException('json_encode() only supports string keys in this compiler build');
                }
                $parts[] = '"'.self::escapeString($key).'":'.self::encodeValue($item);
            }

            return '{'.implode(',', $parts).'}';
        }

        throw new \LogicException('json_encode() unsupported exported type in this compiler build');
    }

    private static function encodeFloat(float $num): string
    {
        if (is_nan($num) || is_infinite($num)) {
            return 'null';
        }
        if ((float) (int) $num === $num && abs($num) < 1.0e15) {
            return (string) (int) $num;
        }

        return rtrim(rtrim(sprintf('%.16G', $num), '0'), '.');
    }

    private static function escapeString(string $value): string
    {
        $out = '';
        $len = \strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            $ord = \ord($c);
            $out .= match ($c) {
                '"' => '\\"',
                '\\' => '\\\\',
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
}
