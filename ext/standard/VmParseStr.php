<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Merge PHP parse_str() output into a VM hashtable (nested keys and lists).
 */
final class VmParseStr
{
    /**
     * One-arg parse_str(): bind parsed keys into the caller symbol table (issue #3708).
     *
     * @param array<int|string, mixed> $params
     */
    public static function importIntoCaller(Frame $caller, array $params): void
    {
        $mainScriptGlobals = null !== $caller->block
            && $caller->block->isMainScript()
            && null !== $caller->vmContext;
        foreach ($params as $key => $value) {
            if (!\is_string($key) && !\is_int($key)) {
                continue;
            }
            $name = (string) $key;
            if (null === $caller->block) {
                continue;
            }
            $idx = $caller->block->slotIndexForVariableName($name);
            if (null !== $idx) {
                if (!isset($caller->scope[$idx])) {
                    $caller->scope[$idx] = new Variable();
                }
                $target = $caller->scope[$idx];
            } else {
                $target = $caller->block->ensureVariableByRuntimeName($name, $caller);
            }
            if (\is_array($value)) {
                $parsed = new HashTable();
                self::mergeInto($parsed, $value);
                $replacement = new Variable(Variable::TYPE_ARRAY);
                $replacement->array($parsed);
                $target->copyFrom($replacement);
                if (null !== $idx) {
                    $caller->initializedSlots[$idx] = true;
                }
                if ($mainScriptGlobals) {
                    $global = $caller->vmContext->ensureGlobal($name);
                    $global->copyFrom($replacement);
                    $caller->vmContext->markGlobalEverAssigned($name);
                }

                continue;
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $replacement = new Variable();
            if (\is_int($value)) {
                $replacement->int($value);
            } elseif (\is_float($value)) {
                $replacement->float($value);
            } elseif (\is_bool($value)) {
                $replacement->bool($value);
            } else {
                $replacement->string((string) $value);
            }
            $target->copyFrom($replacement);
            if (null !== $idx) {
                $caller->initializedSlots[$idx] = true;
            }
            if ($mainScriptGlobals) {
                $global = $caller->vmContext->ensureGlobal($name);
                $global->copyFrom($replacement);
                $caller->vmContext->markGlobalEverAssigned($name);
            }
        }
    }

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
        $bucket = \is_int($key) ? $ht->findIndex($key) : $ht->find((string) $key);
        if (null !== $bucket) {
            $resolved = $bucket->resolveIndirect();
            if (Variable::TYPE_ARRAY === $resolved->type) {
                $resolved->separateArrayForWrite();

                return $resolved->toArray();
            }
        }

        $nested = new HashTable();
        $var = new Variable(Variable::TYPE_ARRAY);
        $var->array($nested);
        if (null !== $bucket) {
            $bucket->copyFrom($var);
        } elseif (\is_int($key)) {
            $ht->addIndex($key, $var);
        } else {
            $ht->add((string) $key, $var);
        }

        $stored = \is_int($key) ? $ht->findIndex($key) : $ht->find((string) $key);
        if (null === $stored) {
            return $nested;
        }
        $storedResolved = $stored->resolveIndirect();
        $storedResolved->separateArrayForWrite();

        return $storedResolved->toArray();
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

    public static function zendTypeLabel(Variable $value): string
    {
        $v = $value->resolveIndirect();

        return match ($v->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
