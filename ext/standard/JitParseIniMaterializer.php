<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Value;

/** Materialize {@see ParseIniEngine} output at JIT compile time (#3263). */
final class JitParseIniMaterializer
{
    /**
     * @param array<string, mixed> $parsed
     */
    public static function materializeParsed(Context $context, array $parsed): Value
    {
        return self::buildHashtable($context, $parsed);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function buildHashtable(Context $context, array $data): Value
    {
        $ht = HashTableHelper::alloc($context);
        $isList = array_is_list($data);
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $child = self::buildHashtable($context, $value);
                if ($isList) {
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setHashtableAt'),
                        $ht,
                        $context->getTypeFromString('size_t')->constInt((int) $key, false),
                        $child
                    );
                } else {
                    $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
                    $context->builder->call(
                        $context->lookupFunction('__hashtable__setStringKeyHashtable'),
                        $ht,
                        $keyStr,
                        $child
                    );
                }
                continue;
            }
            self::setLeafValue($context, $ht, $key, $value, $isList);
        }

        return $ht;
    }

    private static function setLeafValue(Context $context, Value $ht, int|string $key, mixed $value, bool $isList): void
    {
        if (null === $value) {
            if ($isList) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setNullAt'),
                    $ht,
                    $context->getTypeFromString('size_t')->constInt((int) $key, false)
                );
            } else {
                $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyNull'),
                    $ht,
                    $keyStr
                );
            }

            return;
        }
        if (\is_bool($value)) {
            $boolVal = $context->getTypeFromString('int1')->constInt($value ? 1 : 0, false);
            if ($isList) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setBoolAt'),
                    $ht,
                    $context->getTypeFromString('size_t')->constInt((int) $key, false),
                    $boolVal
                );
            } else {
                $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyBool'),
                    $ht,
                    $keyStr,
                    $boolVal
                );
            }

            return;
        }
        if (\is_int($value)) {
            $longVal = $context->getTypeFromString('int64')->constInt($value, false);
            if ($isList) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setLongAt'),
                    $ht,
                    $context->getTypeFromString('size_t')->constInt((int) $key, false),
                    $longVal
                );
            } else {
                $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyLong'),
                    $ht,
                    $keyStr,
                    $longVal
                );
            }

            return;
        }
        if (\is_float($value)) {
            $doubleVal = $context->getTypeFromString('double')->constReal($value, false);
            if ($isList) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setDoubleAt'),
                    $ht,
                    $context->getTypeFromString('size_t')->constInt((int) $key, false),
                    $doubleVal
                );
            } else {
                $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyDouble'),
                    $ht,
                    $keyStr,
                    $doubleVal
                );
            }

            return;
        }
        if (!\is_string($value)) {
            throw new \LogicException('parse_ini_string() compile-time materialization expects scalar leaves');
        }
        $valStr = $context->builder->load($context->constantStringFromString($value));
        if ($isList) {
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringAt'),
                $ht,
                $context->getTypeFromString('size_t')->constInt((int) $key, false),
                $valStr
            );

            return;
        }
        $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
    }
}
