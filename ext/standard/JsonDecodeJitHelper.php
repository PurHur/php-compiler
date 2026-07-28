<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\VmActiveContextJitHelper;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * json_decode() NestedJIT helper (#9359, #20829, #24137).
 *
 * Object/array assoc payloads return {@see HashTable} (Explode #14750 shape — no top-level
 * Variable return; peer #24025). Scalar assoc payloads use int/bool/null/string/float peers.
 * php-src: ext/json/php_json.c — php_json_decode_ex
 */
final class JsonDecodeJitHelper
{
    /** 1 when trimmed payload starts with `[` or `{` (assoc container decode). */
    public static function isAssocContainer(string $payload): int
    {
        $len = \strlen($payload);
        $i = 0;
        while ($i < $len) {
            $c = $payload[$i];
            if (' ' !== $c && "\t" !== $c && "\n" !== $c && "\r" !== $c) {
                break;
            }
            ++$i;
        }
        if ($i >= $len) {
            return 0;
        }
        $c = $payload[$i];

        return ('[' === $c || '{' === $c) ? 1 : 0;
    }

    /** Assoc object/array JSON → native hashtable (#24137). */
    public static function decodeAssocArray(string $payload): HashTable
    {
        self::requireActiveContext();
        $decoded = VmJsonFormat::decode($payload, true);
        if (!\is_array($decoded)) {
            return new HashTable();
        }

        return self::arrayToHashTable($decoded);
    }

    /** Legacy int-wire ABI entry (#20829). */
    public static function decode(string $payload): int
    {
        return self::decodeInt($payload);
    }

    /**
     * JSON integer digit walk (#20829). Non-int scalar payloads return 0.
     */
    public static function decodeInt(string $payload): int
    {
        $len = \strlen($payload);
        if ($len < 1) {
            return 0;
        }
        $i = 0;
        while ($i < $len) {
            $c = $payload[$i];
            if (' ' !== $c && "\t" !== $c && "\n" !== $c && "\r" !== $c) {
                break;
            }
            ++$i;
        }
        if ($i >= $len) {
            return 0;
        }
        if ('-' === $payload[$i] || '+' === $payload[$i]) {
            ++$i;
        }
        if ($i >= $len) {
            return 0;
        }
        for (; $i < $len; ++$i) {
            $c = $payload[$i];
            if ($c < '0' || $c > '9') {
                return 0;
            }
        }

        return (int) \trim($payload);
    }

    /**
     * @param array<string|int, mixed> $value
     */
    private static function arrayToHashTable(array $value): HashTable
    {
        $ht = new HashTable();
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            $slot = self::valueToVariable($item);
            if ($isList) {
                $ht->addIndex((int) $key, $slot);
            } else {
                if (!\is_string($key) && !\is_int($key)) {
                    throw new \LogicException(
                        'json_decode() only supports string keys in this compiler build'
                    );
                }
                $ht->add((string) $key, $slot);
            }
        }

        return $ht;
    }

    private static function valueToVariable(mixed $value): Variable
    {
        $var = new Variable();
        if (null === $value) {
            $var->null();

            return $var;
        }
        if (\is_bool($value)) {
            $var->bool($value);

            return $var;
        }
        if (\is_int($value)) {
            $var->int($value);

            return $var;
        }
        if (\is_float($value)) {
            $var->float($value);

            return $var;
        }
        if (\is_string($value)) {
            $var->string($value);

            return $var;
        }
        if (!\is_array($value)) {
            throw new \LogicException(
                'json_decode() result type not supported in this compiler build'
            );
        }
        $var->array(self::arrayToHashTable($value));

        return $var;
    }

    private static function requireActiveContext(): Context
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            return VmActiveContextJitHelper::resolve();
        }

        return $ctx;
    }
}
