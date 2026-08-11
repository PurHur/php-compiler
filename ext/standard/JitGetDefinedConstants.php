<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for get_defined_constants() (issue #3135). */
final class JitGetDefinedConstants
{
    public static function invoke(Context $context, ?JITVariable $arg0, ?JITVariable $arg1 = null): Value
    {
        if (null === $context->runtime->vmContext) {
            throw new \LogicException('get_defined_constants() requires VM context');
        }

        // php-src never shipped $category — reject 2nd arg like Zend (#28522).
        if (null !== $arg1) {
            throw new \ArgumentCountError(
                'get_defined_constants() expects at most 1 argument, 2 given'
            );
        }

        if (null === $arg0) {
            return self::wrapHashTable(
                $context,
                self::emitHashTablePtr(
                    $context,
                    VmConstants::getDefinedConstants($context->runtime->vmContext, false)
                )
            );
        }

        // Z_PARAM_BOOL compile-time null under strict — catchable TypeError, then stop (#30169).
        if ($context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $arg0->type || ($arg0->isNullConstant ?? false))
        ) {
            JitNativeString::ensureInsertBlock($context);
            ExceptionBridge::emitTypeErrorAndAbort(
                $context,
                'get_defined_constants(): Argument #1 ($categorize) must be of type bool, null given'
            );
            JitNativeString::ensureInsertBlock($context);

            return self::wrapHashTable(
                $context,
                self::emitHashTablePtr(
                    $context,
                    VmConstants::getDefinedConstants($context->runtime->vmContext, false)
                )
            );
        }

        $flat = self::wrapHashTable(
            $context,
            self::emitHashTablePtr(
                $context,
                VmConstants::getDefinedConstants($context->runtime->vmContext, false)
            )
        );

        $categorized = self::wrapHashTable(
            $context,
            self::emitHashTablePtr(
                $context,
                VmConstants::getDefinedConstants($context->runtime->vmContext, true)
            )
        );
        $categorize = self::resolveCategorizeFlag($context, $arg0);
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
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            $zero = $context->getTypeFromString('int64')->constInt(0, false);

            return $context->builder->icmp(
                Builder::INT_NE,
                $context->helper->loadValue($arg),
                $zero
            );
        }
        // Soft null — Z_PARAM_BOOL null→false + E_DEPRECATED (#30169 / #21702).
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return JitBoolArg::lowerCoerceZParamBool(
                $context,
                $arg,
                'get_defined_constants',
                'categorize',
                1
            );
        }
        if (JITVariable::TYPE_HASHTABLE === $arg->type || ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            self::emitCategorizeTypeError($context, 'array');
        }
        if (JITVariable::TYPE_STRING === $arg->type) {
            self::emitCategorizeTypeError($context, 'string');
        }
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            self::emitCategorizeTypeError($context, 'object');
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::resolveCategorizeFlagBoxed($context, $arg);
        }

        throw new \LogicException('get_defined_constants() categorize flag must be boolean');
    }

    private static function resolveCategorizeFlagBoxed(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');

        foreach (
            [
                [VMVariable::TYPE_ARRAY, 'array'],
                [VMVariable::TYPE_OBJECT, 'object'],
                [VMVariable::TYPE_NULL, 'null'],
                [VMVariable::TYPE_STRING, 'string'],
                [VMVariable::TYPE_ENUM_CASE, 'object'],
            ] as [$vmType, $label]
        ) {
            $check = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt($vmType, false));
            $ok = BasicBlockHelper::append($context, 'gdc_cat_ok_'.$label);
            $bad = BasicBlockHelper::append($context, 'gdc_cat_bad_'.$label);
            $context->builder->branchIf($check, $bad, $ok);
            $context->builder->positionAtEnd($bad);
            self::emitCategorizeTypeError($context, $label);
            $context->builder->positionAtEnd($ok);
        }

        $boolBlock = BasicBlockHelper::append($context, 'gdc_cat_bool');
        $longBlock = BasicBlockHelper::append($context, 'gdc_cat_long');
        $mergeBlock = BasicBlockHelper::append($context, 'gdc_cat_merge');
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
        );
        $context->builder->branchIf($isBool, $boolBlock, $longBlock);

        $context->builder->positionAtEnd($boolBlock);
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $i64->constInt(0, false)
        );
        $boolVal = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($firstByte),
            $i8->constInt(0, false)
        );
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($longBlock);
        $zero = $i64->constInt(0, false);
        $longVal = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr),
            $zero
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($boolVal, $boolEnd);
        $phi->addIncoming($longVal, $longEnd);

        return $phi;
    }

    private static function emitCategorizeTypeError(Context $context, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            sprintf(
                'get_defined_constants(): Argument #1 ($categorize) must be of type bool, %s given',
                $given
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
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
                return;
        }
    }
}
