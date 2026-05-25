<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Merge PHP parse_str() output into a VM hashtable (nested keys and lists).
 */
final class VmParseStr
{
    /**
     * @param array<int|string, mixed> $params
     */
    public static function mergeInto(HashTable $ht, array $params): void
    {
        foreach ($params as $key => $value) {
            if (\is_array($value)) {
                $child = self::ensureArrayChild($ht, $key);
                self::mergeInto($child, $value);

                continue;
            }
            if (!\is_scalar($value)) {
                continue;
            }
            self::setScalarEntry($ht, $key, $value);
        }
    }

    /**
     * @param int|string $key
     */
    public static function ensureArrayChild(HashTable $ht, $key): HashTable
    {
        $existing = \is_int($key) ? $ht->findIndex($key) : $ht->find((string) $key);
        if (null !== $existing) {
            $resolved = $existing->resolveIndirect();
            if (Variable::TYPE_ARRAY === $resolved->type) {
                return $resolved->toArray();
            }
        }

        $nested = new HashTable();
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($nested);
        if (null !== $existing) {
            $existing->copyFrom($var);
        } elseif (\is_int($key)) {
            $ht->addIndex($key, $var);
        } else {
            $ht->add((string) $key, $var);
        }

        return $nested;
    }

    /**
     * @param int|string $key
     * @param bool|float|int|string $value
     */
    public static function setScalarEntry(HashTable $ht, $key, $value): void
    {
        $var = new Variable();
        if (\is_int($value)) {
            $var->int($value);
        } elseif (\is_float($value)) {
            $var->float($value);
        } elseif (\is_bool($value)) {
            $var->bool($value);
        } else {
            $var->string((string) $value);
        }
        if (\is_int($key)) {
            $existing = $ht->findIndex($key);
            if (null !== $existing) {
                $existing->copyFrom($var);
            } else {
                $ht->addIndex($key, $var);
            }

            return;
        }
        $existing = $ht->find((string) $key);
        if (null !== $existing) {
            $existing->copyFrom($var);
        } else {
            $ht->add((string) $key, $var);
        }
    }
}
