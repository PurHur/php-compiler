<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * VM http_build_query() — export + native query builder (issue #4781, ext/standard/http.c).
 */
final class VmHttpBuildQuery
{
    public const ENCODING_RFC1738 = 1;
    public const ENCODING_RFC3986 = 2;

    /**
     * Build application/x-www-form-urlencoded query string without host \\http_build_query().
     *
     * @param array<int|string, mixed> $data
     */
    public static function build(
        array $data,
        string $numericPrefix = '',
        string $argSeparator = '&',
        int $encodingType = self::ENCODING_RFC1738
    ): string {
        $useRaw = self::ENCODING_RFC3986 === $encodingType;
        $parts = [];
        foreach ($data as $key => $value) {
            $keyStr = \is_int($key) ? (string) $key : $key;
            if (!\is_string($keyStr)) {
                continue;
            }
            $fullKey = '' === $numericPrefix ? $keyStr : $numericPrefix.'['.$keyStr.']';
            if (\is_array($value)) {
                $nested = self::build($value, $fullKey, $argSeparator, $encodingType);
                if ('' !== $nested) {
                    $parts[] = $nested;
                }
                continue;
            }
            if (null === $value) {
                continue;
            }
            $encodedKey = $useRaw
                ? VmString::rawurlencode($fullKey)
                : VmString::urlencode($fullKey);
            $encodedVal = self::encodeScalarValue($value, $useRaw);
            $parts[] = $encodedKey.'='.$encodedVal;
        }

        return \implode($argSeparator, $parts);
    }

    public static function export(Variable $v): mixed
    {
        $v = $v->resolveIndirect();
        switch ($v->type) {
            case Variable::TYPE_NULL:
                return null;
            case Variable::TYPE_INTEGER:
                return $v->toInt();
            case Variable::TYPE_FLOAT:
                return $v->toFloat();
            case Variable::TYPE_BOOLEAN:
                return $v->toBool();
            case Variable::TYPE_STRING:
                return $v->toString();
            case Variable::TYPE_ARRAY:
                $out = [];
                foreach ($v->toArray()->iterateKeyed(true) as [$key, $value]) {
                    $k = $key->resolveIndirect();
                    if (Variable::TYPE_STRING === $k->type) {
                        $out[$k->toString()] = self::export($value);
                    } elseif (Variable::TYPE_INTEGER === $k->type) {
                        $out[$k->toInt()] = self::export($value);
                    } else {
                        throw new \LogicException(
                            'http_build_query() only supports string or integer keys in this compiler build'
                        );
                    }
                }

                return $out;
            default:
                throw new \LogicException(
                    'http_build_query() value type not supported in this compiler build'
                );
        }
    }

    private static function encodeScalarValue(mixed $value, bool $useRaw): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_string($value)) {
            return $useRaw ? VmString::rawurlencode($value) : VmString::urlencode($value);
        }

        return '';
    }
}
