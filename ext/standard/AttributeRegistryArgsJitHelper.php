<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Attribute ctor-args hashtable for JIT/AOT reflection (#10086 slice).
 *
 * Split from {@see AttributeRegistryJitHelper} so nested JIT compile skips Variable
 * helpers when a compile unit has no attribute ctor args.
 */
final class AttributeRegistryArgsJitHelper
{
    public static function classArgsHashtable(string $classLc, int $idx, string $classEntriesJson): ?HashTable
    {
        foreach (self::decodeClassEntries($classEntriesJson) as $key => $entries) {
            if (0 !== strcasecmp($classLc, $key)) {
                continue;
            }
            if (!isset($entries[$idx])) {
                return null;
            }
            $args = $entries[$idx]['args'] ?? [];
            if ([] === $args) {
                return null;
            }

            return self::buildArgsHashtable($args);
        }

        return null;
    }

    /** @return array<string, list<array{name: string, args: list<array{name: ?string, value: mixed}>}>> */
    private static function decodeClassEntries(string $json): array
    {
        if ('' === $json || '{}' === $json) {
            return [];
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<array{name: ?string, value: mixed}> $args
     */
    private static function buildArgsHashtable(array $args): HashTable
    {
        $ht = new HashTable();
        foreach ($args as $spec) {
            $entryHt = new HashTable();
            if (null !== $spec['name'] && '' !== $spec['name']) {
                $nameVal = new Variable();
                $nameVal->string((string) $spec['name']);
                $entryHt->add('name', $nameVal);
            }
            $value = $spec['value'];
            if (null !== $value) {
                $valueVal = new Variable();
                if (\is_bool($value)) {
                    $valueVal->bool($value);
                } elseif (\is_int($value)) {
                    $valueVal->int($value);
                } elseif (\is_float($value)) {
                    $valueVal->float($value);
                } elseif (\is_string($value)) {
                    $valueVal->string($value);
                } else {
                    throw new \LogicException('Unsupported attribute argument type in JIT helper');
                }
                $entryHt->add('value', $valueVal);
            }
            $entryVar = new Variable(Variable::TYPE_ARRAY);
            $entryVar->array($entryHt);
            $ht->append($entryVar);
        }

        return $ht;
    }
}
