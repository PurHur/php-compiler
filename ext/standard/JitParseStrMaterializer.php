<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Value;

/**
 * Materialize {@see ParseStrEngine} output into LLVM hashtables at JIT compile time (#6308 phase 2).
 */
final class JitParseStrMaterializer
{
    /**
     * @param array<string, mixed> $parsed
     */
    public static function materializeParsed(Context $context, array $parsed): Value
    {
        return self::buildHashtableFromParsed($context, $parsed);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function buildHashtableFromParsed(Context $context, array $data): Value
    {
        $ht = HashTableHelper::alloc($context);
        $isList = array_is_list($data);
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $child = self::buildHashtableFromParsed($context, $value);
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
            if (!\is_string($value)) {
                throw new \LogicException('parse_str() compile-time materialization expects string leaves');
            }
            $valStr = $context->builder->load($context->constantStringFromString($value));
            if ($isList) {
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringAt'),
                    $ht,
                    $context->getTypeFromString('size_t')->constInt((int) $key, false),
                    $valStr
                );
                continue;
            }
            $keyStr = $context->builder->load($context->constantStringFromString((string) $key));
            $context->builder->call(
                $context->lookupFunction('__hashtable__setStringKeyString'),
                $ht,
                $keyStr,
                $valStr
            );
        }

        return $ht;
    }
}
