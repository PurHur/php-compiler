<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_decode() NestedJIT assoc runtime helper (#9359, #20829, #24137).
 *
 * Native __hashtable__* materialization via phpc_native_ht_* (ParseStr #13827 shape).
 * SSOT: {@see VmJsonFormat::decode} + {@see VmJson::importAssoc}.
 * php-src: ext/json/php_json.c — php_json_decode_ex
 */
final class JsonDecodeJitHelper
{
    /** Tag for bridge dispatch: null. */
    public const TAG_NULL = 0;

    /** Tag for bridge dispatch: bool. */
    public const TAG_BOOL = 1;

    /** Tag for bridge dispatch: int. */
    public const TAG_INT = 2;

    /** Tag for bridge dispatch: float. */
    public const TAG_FLOAT = 3;

    /** Tag for bridge dispatch: string. */
    public const TAG_STRING = 4;

    /** Tag for bridge dispatch: array (assoc HT materialization). */
    public const TAG_ARRAY = 5;

    /**
     * Result kind for assoc-mode runtime json_decode() (#24137).
     */
    public static function resultTag(string $payload): int
    {
        $decoded = VmJsonFormat::decode($payload, true);
        if (null === $decoded) {
            return self::TAG_NULL;
        }
        if (\is_bool($decoded)) {
            return self::TAG_BOOL;
        }
        if (\is_int($decoded)) {
            return self::TAG_INT;
        }
        if (\is_float($decoded)) {
            return self::TAG_FLOAT;
        }
        if (\is_string($decoded)) {
            return self::TAG_STRING;
        }
        if (\is_array($decoded)) {
            return self::TAG_ARRAY;
        }

        return self::TAG_NULL;
    }

    /**
     * Assoc-mode array/object JSON → native __hashtable__* as i64 (#24137).
     *
     * @return int native __hashtable__* pointer; 0 when payload is not an array
     */
    public static function decode(string $payload): int
    {
        $decoded = VmJsonFormat::decode($payload, true);
        if (!\is_array($decoded)) {
            return 0;
        }
        $htPtr = (int) phpc_native_ht_alloc();
        self::importIntoNative($htPtr, $decoded);

        return $htPtr;
    }

    public static function decodeInt(string $payload): int
    {
        $decoded = VmJsonFormat::decode($payload, true);

        return \is_int($decoded) ? $decoded : 0;
    }

    public static function decodeBool(string $payload): bool
    {
        $decoded = VmJsonFormat::decode($payload, true);

        return \is_bool($decoded) ? $decoded : false;
    }

    public static function decodeFloat(string $payload): float
    {
        $decoded = VmJsonFormat::decode($payload, true);

        return \is_float($decoded) ? $decoded : 0.0;
    }

    public static function decodeString(string $payload): string
    {
        $decoded = VmJsonFormat::decode($payload, true);

        return \is_string($decoded) ? $decoded : '';
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private static function importIntoNative(int $htPtr, array $data): void
    {
        if ($htPtr <= 0) {
            return;
        }
        $isList = array_is_list($data);
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $childPtr = (int) phpc_native_ht_alloc();
                self::importIntoNative($childPtr, $value);
                if ($isList) {
                    phpc_native_ht_set_hashtable_at($htPtr, (int) $key, $childPtr);
                } else {
                    phpc_native_ht_set_string_key_ht($htPtr, (string) $key, $childPtr);
                }
                continue;
            }
            if ($isList) {
                self::storeIndexValueNative($htPtr, (int) $key, $value);
                continue;
            }
            self::storeStringKeyValueNative($htPtr, (string) $key, $value);
        }
    }

    private static function storeIndexValueNative(int $htPtr, int $index, mixed $value): void
    {
        if (\is_bool($value)) {
            phpc_native_ht_set_long_at($htPtr, $index, $value ? 1 : 0);

            return;
        }
        if (\is_int($value)) {
            phpc_native_ht_set_long_at($htPtr, $index, $value);

            return;
        }
        if (\is_float($value)) {
            phpc_native_ht_set_string_at($htPtr, $index, (string) $value);

            return;
        }
        if (null === $value) {
            phpc_native_ht_set_string_at($htPtr, $index, '');

            return;
        }
        phpc_native_ht_set_string_at($htPtr, $index, (string) $value);
    }

    private static function storeStringKeyValueNative(int $htPtr, string $key, mixed $value): void
    {
        if (\is_bool($value)) {
            phpc_native_ht_set_string_key_long($htPtr, $key, $value ? 1 : 0);

            return;
        }
        if (\is_int($value)) {
            phpc_native_ht_set_string_key_long($htPtr, $key, $value);

            return;
        }
        if (\is_float($value)) {
            phpc_native_ht_set_string_key($htPtr, $key, (string) $value);

            return;
        }
        if (null === $value) {
            phpc_native_ht_set_string_key($htPtr, $key, '');

            return;
        }
        phpc_native_ht_set_string_key($htPtr, $key, (string) $value);
    }
}
