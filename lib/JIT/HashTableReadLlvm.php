<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM hashtable read lowering split from HashTableHelper (#10031). */
final class HashTableReadLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function readIndexedToValueBox(Context $context, Value $ht, Value $index): Variable
    {
        $tag = 'rb'.(string) self::nextSeq();
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $entryPtr = self::listEntryPointer($context, $ht, $index);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entryPtr, $valueMap['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        // Mask IS_REFCOUNTED so VM string tags (4|0x80) still match (#21921).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        // TYPE_ENUM_CASE is not in JitValueBox::copyFromPointer — keep the object arm.
        // Everything else (null/bool/double/long/string/ht/object) goes through the shared
        // typed copy so null/bool/float slots are not misread as int(0) (#24232).
        $enumCaseBlock = BasicBlockHelper::append($context, 'ht_rb_enum_case_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'ht_rb_copy_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_rb_done_'.$tag);

        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ENUM_CASE & 0x7f, false)
        );
        $context->builder->branchIf($isEnumCase, $enumCaseBlock, $copyBlock);

        $context->builder->positionAtEnd($enumCaseBlock);
        $enumObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $entryPtr
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $enumObj
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($copyBlock);
        JitValueBox::copyFromPointer($context, $slot, $entryPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        BasicBlockHelper::branchToFreshContinue($context, 'ht_rb_continue_'.$tag);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    /**
     * Read an associative string-keyed element into a stack {@see __value__} slot.
     */
    /**
     * Lvalue marker for $arr['key'] = … without reading the old value first (#107).
     */

    public static function readStringKeyToValueBox(Context $context, Value $ht, Value $keyStr): Variable
    {
        $tag = 'sk'.(string) self::nextSeq();
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $ht,
            $keyStr
        );
        $done = BasicBlockHelper::append($context, 'ht_sk_done_'.$tag);
        $isNullPtr = $context->builder->icmp(
            Builder::INT_EQ,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $nullBlock = BasicBlockHelper::append($context, 'ht_sk_null_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'ht_sk_copy_'.$tag);
        $context->builder->branchIf($isNullPtr, $nullBlock, $copyBlock);

        // Missing key → null (Zend undefined-index softens to null under @ / in some contexts).
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $context->builder->branch($done);

        // Shared typed copy — null/bool/double must not fall through to writeLong (#24232).
        $context->builder->positionAtEnd($copyBlock);
        JitValueBox::copyFromPointer($context, $slot, $valPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    public static function readObjectKeyToValueBox(Context $context, Value $ht, Value $keyObj): Variable
    {
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readObjectKeyValue'),
            $ht,
            $keyObj
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $nullType = $context->getTypeFromString('int8')->constInt(0, false);
        $isNullPtr = $context->builder->icmp(
            Builder::INT_EQ,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $copy = BasicBlockHelper::append($context, 'ht_ok_copy');
        $done = BasicBlockHelper::append($context, 'ht_ok_done');
        $context->builder->branchIf($isNullPtr, $done, $copy);
        $context->builder->positionAtEnd($copy);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $context->structFieldMap['__value__']['type'])
        );
        $isSet = $context->builder->icmp(
            Builder::INT_NE,
            $typeByte,
            $nullType
        );
        $doCopy = BasicBlockHelper::append($context, 'ht_ok_do_copy');
        $context->builder->branchIf($isSet, $doCopy, $done);
        $context->builder->positionAtEnd($doCopy);
        JitValueBox::copyFromPointer($context, $destPtr, $valPtr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    public static function valuePtrFromDim(Context $context, Variable $dim): Value
    {
        if (Variable::TYPE_VALUE !== $dim->type) {
            throw new \LogicException('valuePtrFromDim requires TYPE_VALUE');
        }

        // KIND_VALUE / by-value __value__ must become __value__* (store+alloca), not
        // loadValue()'s struct-by-value — structGep asserts pointer receivers (#24302
        // HashContextJitHelper emit after #30625 fingerprint churn).
        return JitValueBox::valuePtrFromVariable($context, $dim);
    }

    /** isset() / empty() on a boxed dimension key (#16390, split from HashTableHelper). */
    public static function offsetIsSetValueBoxKey(Context $context, Value $ht, Variable $dim): Value
    {
        $valPtr = self::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_isset_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_isset_vk_long');
        $objectBlock = $fn->appendBasicBlock('ht_isset_vk_obj');
        $falseBlock = $fn->appendBasicBlock('ht_isset_vk_false');
        $merge = $fn->appendBasicBlock('ht_isset_vk_merge');
        $afterString = $fn->appendBasicBlock('ht_isset_vk_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $strResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ht_isset_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $sizeT
        );
        $longResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterLong);
        $floatBlock = $fn->appendBasicBlock('ht_isset_vk_float');
        $afterFloat = $fn->appendBasicBlock('ht_isset_vk_after_float');
        $isNativeDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isVmFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_FLOAT, false)
        );
        $context->builder->branchIf(
            $context->builder->or($isNativeDouble, $isVmFloat),
            $floatBlock,
            $afterFloat
        );
        $context->builder->positionAtEnd($floatBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr);
        // floatToLongWithPrecisionWarning branches into intdiv_float_prec_after — PHI
        // predecessor must be the post-warning insert block, not $floatBlock (#27989 / #27978).
        $truncatedLong = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
            $context,
            $doubleVal
        );
        $floatIndex = $context->builder->truncOrBitCast(
            $truncatedLong,
            $sizeT
        );
        $floatResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $floatIndex
        );
        $floatPred = $context->builder->getInsertBlock();
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterFloat);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_OBJECT, false)
            ),
            $objectBlock,
            $falseBlock
        );
        $context->builder->positionAtEnd($objectBlock);
        // Boxed object: resource handle warn+cast (#29550); else Illegal offset.
        $keyObj = $context->builder->call($context->lookupFunction('__value__readObject'), $valPtr);
        $handle = $context->builder->ptrToInt($keyObj, $context->getTypeFromString('int64'));
        $isRes = \PHPCompiler\ext\standard\JitIsResource::invoke($context, $handle);
        $resOk = $fn->appendBasicBlock('ht_isset_vk_obj_res');
        $objIllegal = $fn->appendBasicBlock('ht_isset_vk_obj_illegal');
        $context->builder->branchIf($isRes, $resOk, $objIllegal);
        $context->builder->positionAtEnd($resOk);
        HashTableResourceKeyLlvm::emitWarning($context, $handle);
        $resIndex = $context->builder->truncOrBitCast($handle, $sizeT);
        $objResResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $resIndex
        );
        $objResPred = $context->builder->getInsertBlock();
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($objIllegal);
        HashTableReadLlvm::emitIllegalOffsetType($context, 'Illegal offset type in isset or empty');
        $objIllegalResult = $i1->constInt(0, false);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($strResult, $stringBlock);
        $phi->addIncoming($longResult, $longBlock);
        $phi->addIncoming($floatResult, $floatPred);
        $phi->addIncoming($objResResult, $objResPred);
        $phi->addIncoming($objIllegalResult, $objIllegal);
        $phi->addIncoming($i1->constInt(0, false), $falseBlock);

        return $phi;
    }

    /**
     * Read an element keyed by a boxed dimension into a stack {@see __value__} slot (#16390).
     *
     * @param bool $emitFloatKeyDeprecation When false, truncate float keys silently (empty-dim #29560).
     */
    public static function readValueBoxKeyToValueBox(
        Context $context,
        Value $ht,
        Variable $dim,
        ?string $superglobalName,
        bool $emitFloatKeyDeprecation = true
    ): Variable {
        $valPtr = self::valuePtrFromDim($context, $dim);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ht_read_vk_str');
        $longBlock = $fn->appendBasicBlock('ht_read_vk_long');
        $objectBlock = $fn->appendBasicBlock('ht_read_vk_obj');
        $nullBlock = $fn->appendBasicBlock('ht_read_vk_null');
        $merge = $fn->appendBasicBlock('ht_read_vk_merge');
        $afterString = $fn->appendBasicBlock('ht_read_vk_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $strBox = null !== $superglobalName
            ? self::readSuperglobalStringKeyToValueBox($context, $ht, $keyStr)
            : self::readStringKeyToValueBox($context, $ht, $keyStr);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $strBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ht_read_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $context->getTypeFromString('size_t')
        );
        $longBox = self::readIndexedToValueBox($context, $ht, $index);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $longBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterLong);
        $floatKeyBlock = $fn->appendBasicBlock('ht_read_vk_float');
        $afterFloatKey = $fn->appendBasicBlock('ht_read_vk_after_float');
        $isNativeDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $isVmFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_FLOAT, false)
        );
        $context->builder->branchIf(
            $context->builder->or($isNativeDouble, $isVmFloat),
            $floatKeyBlock,
            $afterFloatKey
        );
        $context->builder->positionAtEnd($floatKeyBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr);
        // Dim read: float→int E_DEPRECATED for finite fractional + INF/NAN (#27926, #27948).
        // Empty-dim may suppress a second warning after offsetIsSetDim (#29560).
        $truncatedLong = $emitFloatKeyDeprecation
            ? \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning($context, $doubleVal)
            : $context->builder->fptosi($doubleVal, $context->getTypeFromString('int64'));
        $floatIndex = $context->builder->truncOrBitCast(
            $truncatedLong,
            $context->getTypeFromString('size_t')
        );
        $floatBox = self::readIndexedToValueBox($context, $ht, $floatIndex);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $floatBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterFloatKey);
        $nullKeyBlock = $fn->appendBasicBlock('ht_read_vk_null_key');
        $afterNullKey = $fn->appendBasicBlock('ht_read_vk_after_null_key');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_NULL, false)
            ),
            $nullKeyBlock,
            $afterNullKey
        );
        $context->builder->positionAtEnd($nullKeyBlock);
        DynamicPropertyDeprecationGuard::emitNullArrayOffset($context);
        $emptyKey = $context->builder->load($context->constantStringFromString(''));
        $nullKeyBox = null !== $superglobalName
            ? self::readSuperglobalStringKeyToValueBox($context, $ht, $emptyKey)
            : self::readStringKeyToValueBox($context, $ht, $emptyKey);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $nullKeyBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterNullKey);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(Variable::TYPE_OBJECT, false)
            ),
            $objectBlock,
            $nullBlock
        );
        $context->builder->positionAtEnd($objectBlock);
        $keyObj = $context->builder->call($context->lookupFunction('__value__readObject'), $valPtr);
        $objBox = self::readObjectKeyToValueBox($context, $ht, $keyObj);
        JitValueBox::copyFromPointer(
            $context,
            $destPtr,
            JitValueBox::pointer($context, $objBox->value)
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $destPtr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
    }

    /**
     * Read a CGI superglobal string slot without multi-block type dispatch (issue #273).
     * Avoids LLVM dominance failures on ?? left branches when the key is absent at compile time.
     */
    public static function readSuperglobalStringKeyToValueBox(
        Context $context,
        Value $ht,
        Value $keyStr
    ): Variable {
        $tag = 'sg'.(string) self::nextSeq();
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $valPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $keyStr
        );
        $hasValue = BasicBlockHelper::append($context, 'sg_sk_has_'.$tag);
        $missing = BasicBlockHelper::append($context, 'sg_sk_miss_'.$tag);
        $done = BasicBlockHelper::append($context, 'sg_sk_done_'.$tag);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $valPtr,
            $valPtr->typeOf()->constNull()
        );
        $context->builder->branchIf($isNull, $missing, $hasValue);

        $context->builder->positionAtEnd($missing);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($done);

        // Typed copy — $_SESSION (and other supers) may hold i:/b:/N; scalars (#21948).
        // Always-string readString/separate segfaulted on TYPE_NATIVE_LONG / BOOL.
        $context->builder->positionAtEnd($hasValue);
        JitValueBox::copyFromPointer($context, $slot, $valPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
    }

    /** isset() / empty() offset check for string, int, object, or boxed keys (#10031 v4). */
    public static function offsetIsSetDim(Context $context, Value $ht, Variable $dim): Value
    {
        if (Variable::TYPE_NULL === $dim->type) {
            DynamicPropertyDeprecationGuard::emitNullArrayOffset($context);
            $emptyKey = $context->builder->load($context->constantStringFromString(''));

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                $emptyKey
            );
        }
        if (Variable::TYPE_STRING === $dim->type) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                $context->helper->loadValue($dim)
            );
        }
        if (Variable::TYPE_NATIVE_LONG === $dim->type) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($dim),
                $context->getTypeFromString('size_t')
            );

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $ht,
                $index
            );
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $dim->type) {
            $truncated = \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning(
                $context,
                $context->helper->loadValue($dim)
            );
            $index = $context->builder->truncOrBitCast(
                $truncated,
                $context->getTypeFromString('size_t')
            );

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $ht,
                $index
            );
        }
        if (Variable::TYPE_OBJECT === $dim->type) {
            // Resource handles typed as object: warn+cast (#29550); other objects TypeError.
            $i1 = $context->getTypeFromString('int1');
            $slot = BasicBlockHelper::entryAlloca($context, $i1);
            $context->builder->store($i1->constInt(0, false), $slot);
            $done = BasicBlockHelper::append($context, 'ht_isset_obj_done');
            HashTableResourceKeyLlvm::emitObjectDimOrIllegal(
                $context,
                $dim,
                'Illegal offset type in isset or empty',
                static function (Value $index) use ($context, $ht, $slot, $done): void {
                    $exists = $context->builder->call(
                        $context->lookupFunction('__hashtable__offsetIsSet'),
                        $ht,
                        $index
                    );
                    $context->builder->store($exists, $slot);
                    $context->builder->branch($done);
                }
            );
            // illegal path falls through here
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);

            return $context->builder->load($slot);
        }
        if (Variable::TYPE_HASHTABLE === $dim->type) {
            // Array used as offset — Zend TypeError with isset/empty wording (#29567).
            HashTableReadLlvm::emitIllegalOffsetTypeForKey(
                $context,
                $dim,
                'Illegal offset type in isset or empty'
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            return self::offsetIsSetValueBoxKey($context, $ht, $dim);
        }

        throw new \LogicException(
            'isset() on HashTable arrays only supports integer or string indices in this compiler build'
        );
    }

    /**
     * Zend E_WARNING when ++/-- FETCH_DIM_W reads a missing key (#30078, zend_vm_def.h).
     */
    public static function emitUndefinedArrayKeyWarningIfMissing(Context $context, Value $ht, Variable $dim): void
    {
        $exists = self::offsetIsSetDim($context, $ht, $dim);
        $tag = 'diuw'.(string) self::nextSeq();
        $hasKey = BasicBlockHelper::append($context, 'dim_inc_ukey_has_'.$tag);
        $missKey = BasicBlockHelper::append($context, 'dim_inc_ukey_miss_'.$tag);
        $done = BasicBlockHelper::append($context, 'dim_inc_ukey_done_'.$tag);
        $context->builder->branchIf($exists, $hasKey, $missKey);
        $context->builder->positionAtEnd($missKey);
        self::emitUndefinedArrayKeyWarningForDim($context, $dim);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($hasKey);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    /** Emit {@see __compiler_undefined_array_key_warning_*} for a dim operand (#30078). */
    private static function emitUndefinedArrayKeyWarningForDim(Context $context, Variable $dim): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerErrorJit::implement($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            BasicBlockHelper::ensureOpenInsertBlock($context, 'dim_inc_ukey_warn_setup');
        }

        if (Variable::TYPE_NATIVE_LONG === $dim->type) {
            $context->builder->call(
                $context->lookupFunction('__compiler_undefined_array_key_warning_long'),
                $context->helper->loadValue($dim)
            );

            return;
        }
        if (Variable::TYPE_STRING === $dim->type) {
            $keyStr = $context->helper->loadValue($dim);
            $strMap = $context->structFieldMap['__string__'];
            $i8p = $context->getTypeFromString('int8*');
            $keyLen = $context->builder->load($context->builder->structGep($keyStr, $strMap['length']));
            $keyBytes = $context->builder->structGep($keyStr, $strMap['value']);
            $keyCStr = $context->builder->pointerCast($keyBytes, $i8p);
            $context->builder->call(
                $context->lookupFunction('__compiler_undefined_array_key_warning_cstr'),
                $keyCStr,
                $keyLen
            );

            return;
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            $valPtr = self::valuePtrFromDim($context, $dim);
            $valueMap = $context->structFieldMap['__value__'];
            $i8 = $context->getTypeFromString('int8');
            $typeByte = $context->builder->load(
                $context->builder->structGep($valPtr, $valueMap['type'])
            );
            $fn = $context->builder->getInsertBlock()->getParent();
            $strBb = $fn->appendBasicBlock('dim_inc_ukey_str');
            $longBb = $fn->appendBasicBlock('dim_inc_ukey_long');
            $doneBb = $fn->appendBasicBlock('dim_inc_ukey_warn_done');
            $afterStr = $fn->appendBasicBlock('dim_inc_ukey_after_str');
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_STRING, false)
                ),
                $strBb,
                $afterStr
            );
            $context->builder->positionAtEnd($strBb);
            $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
            $strMap = $context->structFieldMap['__string__'];
            $keyLen = $context->builder->load($context->builder->structGep($keyStr, $strMap['length']));
            $keyBytes = $context->builder->structGep($keyStr, $strMap['value']);
            $i8p = $context->getTypeFromString('int8*');
            $keyCStr = $context->builder->pointerCast($keyBytes, $i8p);
            $context->builder->call(
                $context->lookupFunction('__compiler_undefined_array_key_warning_cstr'),
                $keyCStr,
                $keyLen
            );
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($afterStr);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $typeByte,
                    $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
                ),
                $longBb,
                $doneBb
            );
            $context->builder->positionAtEnd($longBb);
            $longKey = $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr);
            $context->builder->call(
                $context->lookupFunction('__compiler_undefined_array_key_warning_long'),
                $longKey
            );
            $context->builder->branch($doneBb);
            $context->builder->positionAtEnd($doneBb);

            return;
        }
        if (Variable::TYPE_NULL === $dim->type) {
            DynamicPropertyDeprecationGuard::emitNullArrayOffset($context);
            $emptyKey = $context->builder->load($context->constantStringFromString(''));
            $strMap = $context->structFieldMap['__string__'];
            $i8p = $context->getTypeFromString('int8*');
            $keyLen = $context->builder->load($context->builder->structGep($emptyKey, $strMap['length']));
            $keyBytes = $context->builder->structGep($emptyKey, $strMap['value']);
            $keyCStr = $context->builder->pointerCast($keyBytes, $i8p);
            $context->builder->call(
                $context->lookupFunction('__compiler_undefined_array_key_warning_cstr'),
                $keyCStr,
                $keyLen
            );
        }
    }

    /**
     * Read an element into a stack {@see __value__} slot (string/int/object/boxed keys; #10031 v4).
     *
     * @param string|null $superglobalName When set, string keys use superglobal-safe read (issue #273).
     */
    /**
     * @param bool $emitFloatKeyDeprecation When false, truncate float keys silently (caller already warned; #29560).
     */
    public static function readDimToValueBox(
        Context $context,
        Value $ht,
        Variable $dim,
        ?string $superglobalName = null,
        bool $emitFloatKeyDeprecation = true
    ): Variable {
        if (Variable::TYPE_NULL === $dim->type) {
            DynamicPropertyDeprecationGuard::emitNullArrayOffset($context);
            $emptyKey = $context->builder->load($context->constantStringFromString(''));
            if (null !== $superglobalName) {
                return self::readSuperglobalStringKeyToValueBox($context, $ht, $emptyKey);
            }

            return self::readStringKeyToValueBox($context, $ht, $emptyKey);
        }
        if (Variable::TYPE_STRING === $dim->type) {
            $key = $context->helper->loadValue($dim);
            if (null !== $superglobalName) {
                return self::readSuperglobalStringKeyToValueBox($context, $ht, $key);
            }

            return self::readStringKeyToValueBox($context, $ht, $key);
        }
        if (Variable::TYPE_NATIVE_LONG === $dim->type) {
            $index = $context->builder->truncOrBitCast(
                $context->helper->loadValue($dim),
                $context->getTypeFromString('size_t')
            );

            return self::readIndexedToValueBox($context, $ht, $index);
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $dim->type) {
            $doubleVal = $context->helper->loadValue($dim);
            $truncated = $emitFloatKeyDeprecation
                ? \PHPCompiler\ext\standard\JitIntdiv::floatToLongWithPrecisionWarning($context, $doubleVal)
                : $context->builder->fptosi($doubleVal, $context->getTypeFromString('int64'));
            $index = $context->builder->truncOrBitCast(
                $truncated,
                $context->getTypeFromString('size_t')
            );

            return self::readIndexedToValueBox($context, $ht, $index);
        }
        if (Variable::TYPE_OBJECT === $dim->type) {
            return self::readObjectKeyToValueBox($context, $ht, $context->helper->loadValue($dim));
        }
        if (Variable::TYPE_VALUE === $dim->type) {
            return self::readValueBoxKeyToValueBox(
                $context,
                $ht,
                $dim,
                $superglobalName,
                $emitFloatKeyDeprecation
            );
        }

        throw new \LogicException(
            'Array fetch only supports integer or string indices in this compiler build'
        );
    }

    /** Materialize empty hashtables for null boxed arrays and object properties (#1086, #17865). */
    public static function ensureHashtablePointer(Context $context, Variable $array): Value
    {
        if (null !== $array->objectPropertySlot && Variable::TYPE_VALUE === ($array->objectPropertyType ?? null)) {
            $voidPtr = $context->getTypeFromString('void*');
            $slot = $array->objectPropertySlot;
            $loaded = $context->builder->pointerCast(
                $context->builder->load($slot),
                $voidPtr
            );
            $slotEmpty = $context->builder->icmp(
                Builder::INT_EQ,
                $loaded,
                $voidPtr->constNull()
            );
            $initSlot = BasicBlockHelper::append($context, 'ht_ensure_prop_slot_init');
            $useSlot = BasicBlockHelper::append($context, 'ht_ensure_prop_slot_use');
            $done = BasicBlockHelper::append($context, 'ht_ensure_prop_slot_done');
            $context->builder->branchIf($slotEmpty, $initSlot, $useSlot);

            $context->builder->positionAtEnd($initSlot);
            $newHt = HashTableWriteLlvm::alloc($context);
            $emptyHt = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $newHt
            );
            $context->type->object->propertyStore($slot, $emptyHt, Variable::TYPE_VALUE);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($useSlot);
            $valPtr = $context->builder->pointerCast(
                $loaded,
                $context->getTypeFromString('__value__*')
            );
            $existing = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $valPtr
            );
            $needsInit = $context->builder->icmp(
                Builder::INT_EQ,
                $existing,
                $existing->typeOf()->constNull()
            );
            $initBox = BasicBlockHelper::append($context, 'ht_ensure_prop_box_init');
            $ready = BasicBlockHelper::append($context, 'ht_ensure_prop_box_ready');
            $context->builder->branchIf($needsInit, $initBox, $ready);

            $context->builder->positionAtEnd($initBox);
            $boxHt = HashTableWriteLlvm::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeHashtable'),
                $valPtr,
                $boxHt
            );
            $context->builder->branch($done);

            $context->builder->positionAtEnd($ready);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($done);
            $htPhi = $context->builder->phi($newHt->typeOf());
            $htPhi->addIncoming($newHt, $initSlot);
            $htPhi->addIncoming($boxHt, $initBox);
            $htPhi->addIncoming($existing, $ready);

            return $htPhi;
        }

        $valPtr = JitValueBox::valuePtrFromVariable($context, $array);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $ht,
            $ht->typeOf()->constNull()
        );
        $init = BasicBlockHelper::append($context, 'ht_ensure_box_init');
        $ready = BasicBlockHelper::append($context, 'ht_ensure_box_ready');
        $done = BasicBlockHelper::append($context, 'ht_ensure_box_done');
        $context->builder->branchIf($isNull, $init, $ready);

        $context->builder->positionAtEnd($init);
        $newHt = HashTableWriteLlvm::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $valPtr,
            $newHt
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($ready);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $result = $context->builder->phi($ht->typeOf());
        $result->addIncoming($newHt, $init);
        $result->addIncoming($ht, $ready);

        return $result;
    }

    /**
     * Load a native {@see __hashtable__*} from a boxed or direct array variable (#107, #18942 v8).
     */
    public static function loadHashtablePointer(Context $context, Variable $array): Value
    {
        if (Variable::TYPE_STRING === $array->type) {
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise(
                $context,
                \PHPCompiler\VM\TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE
            );

            return $context->getTypeFromString('__hashtable__*')->constNull();
        }
        if (null !== $array->objectPropertySlot) {
            if (Variable::TYPE_HASHTABLE === ($array->objectPropertyType ?? null)) {
                return $context->builder->pointerCast(
                    $context->builder->load($array->objectPropertySlot),
                    $context->getTypeFromString('__hashtable__*')
                );
            }

            return self::ensureHashtablePointer($context, $array);
        }
        if (Variable::TYPE_HASHTABLE === $array->type) {
            return $context->helper->loadValue($array);
        }
        if (Variable::TYPE_VALUE === $array->type || $array->valueBoxHashtable) {
            return self::ensureHashtablePointer($context, $array);
        }

        throw new \LogicException(
            'Array offset access requires hashtable or boxed array, got '
            .Variable::getStringType($array->type)
        );
    }

    public static function listEntryPointer(Context $context, Value $ht, Value $index): Value
    {
        $map = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load(
            $context->builder->structGep($ht, $map['values'])
        );

        return $context->builder->inBoundsGep($values, $index);
    }

    /**
     * Live child hashtable at a packed index (nested FETCH_DIM_W intermediate, #24011).
     * Mirrors {@see Builtin\Type\HashTable} `__hashtable__readStringKeyHashtable` for int keys.
     */
    public static function readIndexedHashtable(Context $context, Value $ht, Value $index): Value
    {
        $entry = self::listEntryPointer($context, $ht, $index);

        return $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $entry
        );
    }

    public static function readStringAt(Context $context, Value $ht, Value $index): Value
    {
        $entry = self::listEntryPointer($context, $ht, $index);

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
    }

    /**
     * Walk native __strkey_node__ list — shared by extract/compact scope import (#19035).
     *
     * @param callable(Context, Value, Value): void $body  ($keyStr, $valEntry)
     */
    public static function forEachStringKeyNode(
        Context $context,
        Value $ht,
        string $tagPrefix,
        callable $body
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $tag = $tagPrefix.'_'.(string) self::nextSeq();
        $walkSlot = $context->builder->alloca($nodePtrType, 1, $tag.'_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);

        $headBb = BasicBlockHelper::append($context, $tag.'_head');
        $bodyBb = BasicBlockHelper::append($context, $tag.'_body');
        $nextBb = BasicBlockHelper::append($context, $tag.'_next');
        $doneBb = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($headBb);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $doneBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $body($context, $keyStr, $valEntry);
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->builder->branch($nextBb);
        }

        $context->builder->positionAtEnd($nextBb);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $walkSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($doneBb);
    }

    /**
     * Walk packed list indices 0..count-1 reading string elements (#19035).
     *
     * @param callable(Context, Value, Value): void $body  ($index, $str)
     */
    public static function forEachIndexedStringAt(
        Context $context,
        Value $ht,
        Value $count,
        string $tagPrefix,
        callable $body
    ): void {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $tag = $tagPrefix.'_'.(string) self::nextSeq();
        $idxSlot = $context->builder->alloca($sizeT, 1, $tag.'_idx');
        $context->builder->store($zero, $idxSlot);

        $headBb = BasicBlockHelper::append($context, $tag.'_head');
        $bodyBb = BasicBlockHelper::append($context, $tag.'_body');
        $advanceBb = BasicBlockHelper::append($context, $tag.'_advance');
        $doneBb = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($headBb);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $doneBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $str = self::readStringAt($context, $ht, $idx);
        $body($context, $idx, $str);
        if (null === $context->builder->getInsertBlock()?->getTerminator()) {
            $context->builder->branch($advanceBb);
        }

        $context->builder->positionAtEnd($advanceBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($headBb);

        $context->builder->positionAtEnd($doneBb);
    }

    public static function emitIllegalOffsetType(Context $context, string $message = 'Illegal offset type'): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
    }

    /**
     * Array-literal / dim-write illegal key — PROFILE≥8.3 typed TypeError (#28628, zend_illegal_container_offset).
     */
    public static function emitIllegalOffsetTypeForKey(
        Context $context,
        Variable $key,
        string $legacyMessage = 'Illegal offset type'
    ): void {
        self::emitIllegalOffsetType(
            $context,
            self::illegalOffsetMessageForJitKey($context, $key, $legacyMessage)
        );
    }

    /**
     * Resolve zend_zval_type_name()-shaped label for a compile-time JIT array key (#28628).
     */
    public static function illegalOffsetMessageForJitKey(
        Context $context,
        Variable $key,
        string $legacyMessage = 'Illegal offset type'
    ): string {
        if (Variable::TYPE_HASHTABLE === $key->type) {
            return \PHPCompiler\VM\EnumCaseSupport::formatIllegalContainerOffsetMessage(
                'array',
                $legacyMessage
            );
        }

        $typeName = 'object';
        if (null !== $key->compileTimeEnumCase && isset($key->compileTimeEnumCase['classId'])) {
            $name = $context->type->object->classNameForId((int) $key->compileTimeEnumCase['classId']);
            if (\is_string($name) && '' !== $name) {
                $typeName = $name;
            }
        } elseif (null !== $key->objectPropertyClassName && '' !== $key->objectPropertyClassName) {
            $typeName = $key->objectPropertyClassName;
        } elseif (null !== $key->magicGetOverloadedClass && '' !== $key->magicGetOverloadedClass) {
            $typeName = $key->magicGetOverloadedClass;
        }

        return \PHPCompiler\VM\EnumCaseSupport::formatIllegalContainerOffsetMessage(
            $typeName,
            $legacyMessage
        );
    }

}
