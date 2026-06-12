<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for iptcparse() (ext/standard/iptc.c; issue #6104). */
final class JitIptcParse
{
    public static function invoke(Context $context, JITVariable $dataArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($dataArg);
        if (null === $literal) {
            throw new \LogicException(
                'iptcparse() requires a compile-time string literal for JIT/AOT in this compiler build'
            );
        }

        $parsed = VmIptc::parse($literal);
        if (false === $parsed) {
            return $context->constantFromBool(false);
        }

        return self::wrapHashTable($context, self::emitParsed($context, $parsed));
    }

    /**
     * @param array<string, list<string>> $parsed
     */
    private static function emitParsed(Context $context, array $parsed): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        foreach ($parsed as $key => $values) {
            $list = HashTableHelper::alloc($context);
            foreach ($values as $index => $value) {
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString($value))
                );
                HashTableHelper::setAtIndex(
                    $context,
                    $list,
                    $i64->constInt($index, false),
                    $jit
                );
            }
            $listVar = new JITVariable(
                $context,
                JITVariable::TYPE_HASHTABLE,
                JITVariable::KIND_VALUE,
                $list
            );
            $keyStr = $context->builder->load($context->constantStringFromString($key));
            HashTableHelper::setAtStringKey(
                $context,
                $ht,
                $keyStr,
                $listVar
            );
        }

        return $ht;
    }

    private static function wrapHashTable(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
