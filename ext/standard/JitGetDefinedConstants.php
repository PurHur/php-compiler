<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\IssetHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_defined_constants() (issue #3135). */
final class JitGetDefinedConstants
{
    public static function invoke(Context $context, ?JITVariable $categorizeArg): Value
    {
        if (null === $context->runtime->vmContext) {
            throw new \LogicException('get_defined_constants() requires VM context');
        }
        $flat = self::wrapHashTable(
            $context,
            self::emitHashTablePtr(
                $context,
                VmConstants::getDefinedConstants($context->runtime->vmContext, false)
            )
        );
        if (null === $categorizeArg) {
            return $flat;
        }

        $categorized = self::wrapHashTable(
            $context,
            self::emitHashTablePtr(
                $context,
                VmConstants::getDefinedConstants($context->runtime->vmContext, true)
            )
        );
        $categorize = self::resolveCategorizeFlag($context, $categorizeArg);
        $tag = 'gdc'.(string) ++self::$seq;
        $useCat = BasicBlockHelper::append($context, 'gdc_cat_'.$tag);
        $useFlat = BasicBlockHelper::append($context, 'gdc_flat_'.$tag);
        $done = BasicBlockHelper::append($context, 'gdc_done_'.$tag);
        $context->builder->branchIf($categorize, $useCat, $useFlat);

        $context->builder->positionAtEnd($useCat);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($useFlat);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        $ptrType = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($ptrType);
        $phi->addIncoming($categorized, $useCat);
        $phi->addIncoming($flat, $useFlat);

        return $phi;
    }

    private static int $seq = 0;

    private static function resolveCategorizeFlag(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
            $typeByte = $context->builder->load(
                $context->builder->structGep(
                    $valuePtr,
                    $context->structFieldMap['__value__']['type']
                )
            );
            $i8 = $context->getTypeFromString('int8');
            $isBool = $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
            );
            if (!$isBool) {
                throw new \LogicException('get_defined_constants() categorize flag must be boolean');
            }
            $longVal = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $valuePtr
            );

            return $context->builder->truncOrBitCast(
                $longVal,
                $context->getTypeFromString('int1')
            );
        }

        throw new \LogicException('get_defined_constants() categorize flag must be boolean');
    }

    private static function emitHashTablePtr(Context $context, HashTable $table): Value
    {
        $ht = HashTableHelper::alloc($context);
        foreach ($table->iterateKeyed(true) as [$keyVar, $valueVar]) {
            if (VMVariable::TYPE_STRING !== $keyVar->type) {
                continue;
            }
            $keyStr = $context->builder->load(
                $context->constantStringFromString($keyVar->toString())
            );
            self::storeVmVariable($context, $ht, $keyStr, $valueVar);
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

    private static function storeVmVariable(
        Context $context,
        Value $ht,
        Value $keyStr,
        VMVariable $value
    ): void {
        $resolved = $value->resolveIndirect();
        switch ($resolved->type) {
            case VMVariable::TYPE_ARRAY:
                $nestedHt = self::emitHashTablePtr($context, $resolved->toArray());
                $context->builder->call(
                    $context->lookupFunction('__hashtable__setStringKeyHashtable'),
                    $ht,
                    $keyStr,
                    $nestedHt
                );

                return;
            case VMVariable::TYPE_INTEGER:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_BOOLEAN:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt($resolved->toBool() ? 1 : 0, false)
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_FLOAT:
                return;
            case VMVariable::TYPE_STRING:
                $jit = new JITVariable(
                    $context,
                    JITVariable::TYPE_STRING,
                    JITVariable::KIND_VALUE,
                    $context->builder->load($context->constantStringFromString($resolved->toString()))
                );
                HashTableHelper::setAtStringKey($context, $ht, $keyStr, $jit);

                return;
            case VMVariable::TYPE_NULL:
                return;
            default:
                throw new \LogicException(
                    'get_defined_constants() unsupported constant type: '
                    .VMVariable::getStringType($resolved->type)
                );
        }
    }
}
