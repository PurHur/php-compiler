<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\EnumCaseSupport;
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
        int $encodingType = self::ENCODING_RFC1738,
        bool $legacyIntEncodingArg = false
    ): string {
        return self::buildWithKeyPrefix($data, $numericPrefix, $argSeparator, $encodingType, null, $legacyIntEncodingArg);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function buildWithKeyPrefix(
        array $data,
        string $numericPrefix,
        string $argSeparator,
        int $encodingType,
        ?string $keyPrefix,
        bool $legacyIntEncodingArg = false
    ): string {
        $useRaw = !$legacyIntEncodingArg && self::ENCODING_RFC3986 === $encodingType;
        $parts = [];
        foreach ($data as $key => $value) {
            $encoded = self::encodeEntry(
                $key,
                $value,
                $numericPrefix,
                $keyPrefix,
                $useRaw,
                $argSeparator,
                $encodingType,
                $legacyIntEncodingArg
            );
            if ('' !== $encoded) {
                $parts[] = $encoded;
            }
        }

        return \implode($argSeparator, $parts);
    }

    /**
     * Build query string directly from VM HashTable (nested JIT safe — no mixed PHP array stores).
     */
    public static function buildFromHashTable(
        HashTable $data,
        string $numericPrefix = '',
        string $argSeparator = '&',
        int $encodingType = self::ENCODING_RFC1738,
        bool $legacyIntEncodingArg = false
    ): string {
        return self::buildHashTableWithKeyPrefix($data, $numericPrefix, $argSeparator, $encodingType, null, $legacyIntEncodingArg);
    }

    private static function buildHashTableWithKeyPrefix(
        HashTable $data,
        string $numericPrefix,
        string $argSeparator,
        int $encodingType,
        ?string $keyPrefix,
        bool $legacyIntEncodingArg = false
    ): string {
        $useRaw = !$legacyIntEncodingArg && self::ENCODING_RFC3986 === $encodingType;
        $parts = [];
        foreach ($data->exportKeyValuePairs(true) as [$keyVar, $valVar]) {
            $encoded = self::encodeHashTableEntry(
                $keyVar,
                $valVar,
                $numericPrefix,
                $keyPrefix,
                $useRaw,
                $argSeparator,
                $encodingType,
                $legacyIntEncodingArg
            );
            if ('' !== $encoded) {
                $parts[] = $encoded;
            }
        }

        return \implode($argSeparator, $parts);
    }

    private static function encodeHashTableEntry(
        Variable $keyVar,
        Variable $valVar,
        string $numericPrefix,
        ?string $keyPrefix,
        bool $useRaw,
        string $argSeparator,
        int $encodingType,
        bool $legacyIntEncodingArg = false
    ): string {
        $key = $keyVar->resolveIndirect();
        $isIntKey = Variable::TYPE_INTEGER === $key->type;
        $keyStr = $isIntKey ? (string) $key->toInt() : $key->toString();
        if (!\is_string($keyStr)) {
            return '';
        }

        $value = $valVar->resolveIndirect();
        if (Variable::TYPE_ARRAY === $value->type) {
            $childPrefix = self::buildChildKeyPrefix(
                $keyStr,
                $isIntKey,
                $numericPrefix,
                $keyPrefix,
                $useRaw
            );

            return self::buildHashTableWithKeyPrefix(
                $value->toArray(),
                '',
                $argSeparator,
                $encodingType,
                $childPrefix,
                $legacyIntEncodingArg
            );
        }
        if (Variable::TYPE_NULL === $value->type) {
            return '';
        }

        $scalar = self::variableToScalar($value);
        if (null === $scalar) {
            return '';
        }

        $fullKey = self::buildScalarKey($keyStr, $isIntKey, $numericPrefix, $keyPrefix, $useRaw);
        $encodedKey = self::encodeKeyForOutput($fullKey, $isIntKey, $numericPrefix, $keyPrefix, $useRaw);
        $encodedVal = self::encodeScalarValue($scalar, $useRaw);

        return $encodedKey.'='.$encodedVal;
    }

    private static function variableToScalar(Variable $v): mixed
    {
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
            default:
                return '';
        }
    }

    /**
     * @param int|string $key
     */
    private static function encodeEntry(
        int|string $key,
        mixed $value,
        string $numericPrefix,
        ?string $keyPrefix,
        bool $useRaw,
        string $argSeparator,
        int $encodingType,
        bool $legacyIntEncodingArg = false
    ): string {
        $isIntKey = \is_int($key);
        $keyStr = $isIntKey ? (string) $key : $key;
        if (!\is_string($keyStr)) {
            return '';
        }

        if (\is_array($value)) {
            $childPrefix = self::buildChildKeyPrefix(
                $keyStr,
                $isIntKey,
                $numericPrefix,
                $keyPrefix,
                $useRaw
            );

            return self::buildWithKeyPrefix($value, '', $argSeparator, $encodingType, $childPrefix, $legacyIntEncodingArg);
        }
        if (null === $value) {
            return '';
        }

        $fullKey = self::buildScalarKey($keyStr, $isIntKey, $numericPrefix, $keyPrefix, $useRaw);
        $encodedKey = self::encodeKeyForOutput($fullKey, $isIntKey, $numericPrefix, $keyPrefix, $useRaw);
        $encodedVal = self::encodeScalarValue($value, $useRaw);

        return $encodedKey.'='.$encodedVal;
    }

    private static function encodeKeyForOutput(
        string $fullKey,
        bool $isIntKey,
        string $numericPrefix,
        ?string $keyPrefix,
        bool $useRaw
    ): string {
        if (null !== $keyPrefix) {
            return $fullKey;
        }
        if ($isIntKey) {
            return $fullKey;
        }

        return $useRaw ? VmString::rawurlencode($fullKey) : VmString::urlencode($fullKey);
    }

    private static function buildChildKeyPrefix(
        string $keyStr,
        bool $isIntKey,
        string $numericPrefix,
        ?string $keyPrefix,
        bool $useRaw
    ): string {
        if (null !== $keyPrefix) {
            if ($isIntKey) {
                return $keyPrefix.$keyStr.'%5D%5B';
            }

            $encoded = $useRaw ? VmString::rawurlencode($keyStr) : VmString::urlencode($keyStr);

            return $keyPrefix.$encoded.'%5D%5B';
        }
        if ($isIntKey) {
            if ('' !== $numericPrefix) {
                return $numericPrefix.$keyStr.'%5B';
            }

            return $keyStr.'%5B';
        }

        $encoded = $useRaw ? VmString::rawurlencode($keyStr) : VmString::urlencode($keyStr);

        return $encoded.'%5B';
    }

    private static function buildScalarKey(
        string $keyStr,
        bool $isIntKey,
        string $numericPrefix,
        ?string $keyPrefix,
        bool $useRaw
    ): string {
        if (null !== $keyPrefix) {
            if ($isIntKey) {
                return $keyPrefix.$keyStr.'%5D';
            }

            $encoded = $useRaw ? VmString::rawurlencode($keyStr) : VmString::urlencode($keyStr);

            return $keyPrefix.$encoded.'%5D';
        }
        if ($isIntKey && '' !== $numericPrefix) {
            return $numericPrefix.$keyStr;
        }

        return $keyStr;
    }

    public static function export(Variable $v, ?Frame $frame = null): mixed
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
            case Variable::TYPE_ENUM_CASE:
                return self::exportEnumCase($v, $frame);
            case Variable::TYPE_OBJECT:
                if (EnumCaseSupport::isEnumCaseVariable($v)) {
                    return self::exportEnumCase($v, $frame);
                }
                if (null === $frame) {
                    throw new \LogicException(
                        'http_build_query() value type not supported in this compiler build'
                    );
                }

                return self::exportObject($v, $frame);
            case Variable::TYPE_ARRAY:
                return self::exportArray($v, $frame);
            default:
                throw new \LogicException(
                    'http_build_query() value type not supported in this compiler build'
                );
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function exportArray(Variable $v, ?Frame $frame): array
    {
        $out = [];
        foreach ($v->toArray()->iterateKeyed(true) as [$key, $value]) {
            $k = $key->resolveIndirect();
            if (Variable::TYPE_STRING === $k->type) {
                $out[$k->toString()] = self::export($value, $frame);
            } elseif (Variable::TYPE_INTEGER === $k->type) {
                $out[$k->toInt()] = self::export($value, $frame);
            } else {
                throw new \LogicException(
                    'http_build_query() only supports string or integer keys in this compiler build'
                );
            }
        }

        return $out;
    }

    /**
     * php-src http.c — object operands use get_object_vars() semantics (public properties).
     *
     * @return array<string, mixed>
     */
    private static function exportObject(Variable $v, Frame $frame): array
    {
        $props = VmReflection::getObjectVars($v, $frame);

        return self::exportArray($props, $frame);
    }

    /**
     * php-src http.c — backed/unit enum cases expand to name[/value] sub-arrays.
     *
     * @return array<string, mixed>
     */
    private static function exportEnumCase(Variable $v, ?Frame $frame): array
    {
        $out = [];
        foreach (EnumCaseSupport::objectVarsForCaseVariable($v) as $name => $propVar) {
            $out[$name] = self::export($propVar, $frame);
        }

        return $out;
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
