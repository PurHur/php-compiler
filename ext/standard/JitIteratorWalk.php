<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ArrayCountRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\IteratorProtocolHelper;
use PHPCompiler\JIT\JitIterableArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT lowering for iterator_count() and iterator_apply() (#3313, php-src ext/spl/iterator.c).
 *
 * Thin AOT: arrays via {@see ArrayCountRuntime}; HT-backed SPL via `__spl_ht`;
 * TypeError via {@see ExceptionBridge} (#27633).
 */
final class JitIteratorWalk
{
    public static function count(Context $context, Variable $iterable): Value
    {
        ExceptionBridge::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        if (Variable::TYPE_NULL === $iterable->type || ($iterable->isNullConstant ?? false)) {
            JitIterableArg::emitIterableTypeErrorAndAbort(
                $context,
                'iterator_count',
                0,
                'iterator',
                'null'
            );

            return $zero;
        }

        if (!JitIterableArg::guardIterableOperand($context, $iterable, 'iterator_count')) {
            return $zero;
        }

        GeneratorHelper::ensureTypes($context);
        $gen = self::resolveGenerator($iterable);
        if (null !== $gen) {
            return self::countGenerator($context, $gen);
        }

        if ($iterable->type & Variable::IS_NATIVE_ARRAY || Variable::TYPE_HASHTABLE === $iterable->type) {
            return ArrayCountRuntime::numElements($context, $iterable);
        }

        if (Variable::TYPE_VALUE === $iterable->type || JitValueBox::isValueOperand($iterable)) {
            // Nested `new` often leaves TYPE_VALUE over an `__object__*` (#30273).
            if (Variable::KIND_VALUE === $iterable->kind) {
                $llvmType = $context->getStringFromType($iterable->value->typeOf());
                if ('__object__*' === $llvmType) {
                    $asObj = new Variable(
                        $context,
                        Variable::TYPE_OBJECT,
                        Variable::KIND_VALUE,
                        $iterable->value
                    );

                    return self::countObject($context, $asObj);
                }
            }

            return self::countBoxedValue($context, $iterable);
        }

        if (Variable::TYPE_OBJECT === $iterable->type) {
            return self::countObject($context, $iterable);
        }

        JitIterableArg::emitIterableTypeErrorForOperandAndAbort(
            $context,
            'iterator_count',
            0,
            'iterator',
            $iterable
        );

        return $zero;
    }

    public static function apply(Context $context, Variable $iterable, Variable $callback, Variable $params): Value
    {
        ExceptionBridge::ensureLinked($context);
        if (!JitIterableArg::guardTraversableOperand($context, $iterable, 'iterator_apply')) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        if (!ArrayMapCallbackPolicy::isClosureJitLowerable($callback)) {
            throw new \LogicException(
                'iterator_apply() requires a compile-time closure callback in this compiler build'
            );
        }
        $closureCall = $callback->closureCall;
        if (null === $closureCall) {
            throw new \LogicException(ArrayMapCallbackPolicy::jitRejectionMessage());
        }
        GeneratorHelper::ensureTypes($context);
        $gen = self::resolveGenerator($iterable);
        if (null !== $gen) {
            return self::applyGenerator($context, $gen, $closureCall);
        }
        // Arrays rejected by guardTraversableOperand (#19839); native/hashtable paths are unreachable.
        if (Variable::TYPE_OBJECT === $iterable->type || Variable::TYPE_VALUE === $iterable->type) {
            $walk = $iterable;
            if (
                Variable::TYPE_VALUE === $iterable->type
                && Variable::KIND_VALUE === $iterable->kind
                && '__object__*' === $context->getStringFromType($iterable->value->typeOf())
            ) {
                $walk = new Variable(
                    $context,
                    Variable::TYPE_OBJECT,
                    Variable::KIND_VALUE,
                    $iterable->value
                );
            }

            return self::applyObject($context, $walk, $closureCall);
        }

        JitIterableArg::emitIterableTypeErrorForOperandAndAbort(
            $context,
            'iterator_apply',
            0,
            'iterator',
            $iterable,
            false
        );

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private static function resolveGenerator(Variable $iterable): ?Variable
    {
        if (GeneratorHelper::isGeneratorVariable($iterable)) {
            return $iterable;
        }

        return null;
    }

    /**
     * Boxed `__value__` — array HT / Traversable object / null (#27633).
     */
    private static function countBoxedValue(Context $context, Variable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $htTag = $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false);
        $objTag = $i8->constInt(VmVariable::TYPE_OBJECT & 0x7f, false);
        $nullTag = $i8->constInt(VmVariable::TYPE_NULL & 0x7f, false);
        $isHt = $context->builder->icmp(Builder::INT_EQ, $kind, $htTag);
        $isObj = $context->builder->icmp(Builder::INT_EQ, $kind, $objTag);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $kind, $nullTag);

        $resultSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $htBb = BasicBlockHelper::append($context, 'ic_box_ht');
        $afterHt = BasicBlockHelper::append($context, 'ic_box_after_ht');
        $objBb = BasicBlockHelper::append($context, 'ic_box_obj');
        $afterObj = BasicBlockHelper::append($context, 'ic_box_after_obj');
        $nullBb = BasicBlockHelper::append($context, 'ic_box_null');
        $badBb = BasicBlockHelper::append($context, 'ic_box_bad');
        $done = BasicBlockHelper::append($context, 'ic_box_done');

        $context->builder->branchIf($isHt, $htBb, $afterHt);

        $context->builder->positionAtEnd($htBb);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $htVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $ht);
        $context->builder->store(ArrayCountRuntime::numElements($context, $htVar), $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterHt);
        $context->builder->branchIf($isObj, $objBb, $afterObj);

        $context->builder->positionAtEnd($objBb);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $context->builder->store(self::countObject($context, $objVar), $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterObj);
        $context->builder->branchIf($isNull, $nullBb, $badBb);

        $context->builder->positionAtEnd($nullBb);
        JitIterableArg::emitIterableTypeErrorAndAbort($context, 'iterator_count', 0, 'iterator', 'null');
        $context->builder->store($i64->constInt(0, false), $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($badBb);
        JitIterableArg::emitIterableTypeErrorFromValueBoxAndAbort(
            $context,
            'iterator_count',
            0,
            'iterator',
            $valuePtr
        );
        $context->builder->store($i64->constInt(0, false), $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    /**
     * HT-backed SPL (`__spl_ht`) first — Iterator protocol aborts under thin AOT (#27633).
     */
    private static function countObject(Context $context, Variable $iterable): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $receiver = IteratorProtocolHelper::normalizeObjectReceiver($context, $iterable);
        $htIds = self::htBackedClassIds($context);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $done = BasicBlockHelper::append($context, 'ic_obj_done');

        if ([] !== $htIds) {
            $objPtr = $context->helper->loadValue($receiver);
            $classId = $context->builder->load(
                $context->builder->structGep(
                    $objPtr,
                    $context->structFieldMap['__object__']['class_id']
                )
            );
            $classIdTy = $classId->typeOf();
            $isHt = null;
            foreach ($htIds as $id) {
                $eq = $context->builder->icmp(
                    Builder::INT_EQ,
                    $classId,
                    $classIdTy->constInt($id, false)
                );
                $isHt = null === $isHt ? $eq : $context->builder->or($isHt, $eq);
            }
            $htBb = BasicBlockHelper::append($context, 'ic_obj_ht');
            $protoBb = BasicBlockHelper::append($context, 'ic_obj_proto');
            $context->builder->branchIf($isHt, $htBb, $protoBb);

            $context->builder->positionAtEnd($htBb);
            $htVar = $context->type->object->splBackingHashtable($receiver);
            $context->builder->store(ArrayCountRuntime::numElements($context, $htVar), $resultSlot);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($protoBb);
        }

        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $iterable, null)) {
            $context->builder->store(self::countIteratorObject($context, $iterable), $resultSlot);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);

            return $context->builder->load($resultSlot);
        }

        JitIterableArg::emitIterableTypeErrorForOperandAndAbort(
            $context,
            'iterator_count',
            0,
            'iterator',
            $iterable
        );
        $context->builder->store($i64->constInt(0, false), $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    /** @return list<int> */
    private static function htBackedClassIds(Context $context): array
    {
        $ids = [];
        $wanted = array_flip(SplOuterIteratorHt::classNamesLc());
        foreach ($context->type->object->allClassNamesById() as $classId => $className) {
            $lc = strtolower(ltrim((string) $className, '\\'));
            if (isset($wanted[$lc])) {
                $ids[] = (int) $classId;
            }
        }

        return $ids;
    }

    private static function countGenerator(Context $context, Variable $gen): Value
    {
        GeneratorHelper::compileAssertGeneratorIterableForRewind($context, $gen);
        GeneratorHelper::compileIterReset($context, $gen);
        $countSlot = $context->builder->alloca($context->getTypeFromString('int64'), 1, 'iterator_count_n');
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, false),
            $countSlot
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('iterator_count_head');
        $body = $fn->appendBasicBlock('iterator_count_body');
        $done = $fn->appendBasicBlock('iterator_count_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $valid = GeneratorHelper::compileIterValid($context, $gen);
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function countIteratorObject(Context $context, Variable $iterable): Value
    {
        $receiver = IteratorProtocolHelper::normalizeObjectReceiver($context, $iterable);
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'rewind');
        $countSlot = $context->builder->alloca($context->getTypeFromString('int64'), 1, 'iterator_count_obj_n');
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, false),
            $countSlot
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('iterator_count_obj_head');
        $body = $fn->appendBasicBlock('iterator_count_obj_body');
        $done = $fn->appendBasicBlock('iterator_count_obj_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $valid = IteratorProtocolHelper::invokeIteratorMethodBool($context, $receiver, 'valid');
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'next');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function applyGenerator(Context $context, Variable $gen, Call $closureCall): Value
    {
        GeneratorHelper::compileIterReset($context, $gen);
        $countSlot = $context->builder->alloca($context->getTypeFromString('int64'), 1, 'iterator_apply_gen_n');
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, false),
            $countSlot
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('iterator_apply_gen_head');
        $body = $fn->appendBasicBlock('iterator_apply_gen_body');
        $done = $fn->appendBasicBlock('iterator_apply_gen_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $valid = GeneratorHelper::compileIterValid($context, $gen);
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        // php-src — count each invoked iteration before checking callback (#25326).
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        $value = GeneratorHelper::compileIterValue($context, $gen);
        $key = GeneratorHelper::compileIterKey($context, $gen);
        $result = $closureCall->call($context, $value, $key);
        $keep = IteratorProtocolHelper::truthyI1($context, $result);
        $advance = $fn->appendBasicBlock('iterator_apply_gen_advance');
        $context->builder->branchIf($keep, $advance, $done);
        $context->builder->positionAtEnd($advance);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    /**
     * HT-backed SPL first (LimitIterator snapshot) — protocol methods abort under thin AOT (#30273).
     */
    private static function applyObject(Context $context, Variable $iterable, Call $closureCall): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $receiver = IteratorProtocolHelper::normalizeObjectReceiver($context, $iterable);
        $htIds = self::htBackedClassIds($context);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $done = BasicBlockHelper::append($context, 'ia_obj_done');

        if ([] !== $htIds) {
            $objPtr = $context->helper->loadValue($receiver);
            $classId = $context->builder->load(
                $context->builder->structGep(
                    $objPtr,
                    $context->structFieldMap['__object__']['class_id']
                )
            );
            $classIdTy = $classId->typeOf();
            $isHt = null;
            foreach ($htIds as $id) {
                $eq = $context->builder->icmp(
                    Builder::INT_EQ,
                    $classId,
                    $classIdTy->constInt($id, false)
                );
                $isHt = null === $isHt ? $eq : $context->builder->or($isHt, $eq);
            }
            $htBb = BasicBlockHelper::append($context, 'ia_obj_ht');
            $protoBb = BasicBlockHelper::append($context, 'ia_obj_proto');
            $context->builder->branchIf($isHt, $htBb, $protoBb);

            $context->builder->positionAtEnd($htBb);
            $htVar = $context->type->object->splBackingHashtable($receiver);
            $context->builder->store(self::applyHashtable($context, $htVar, $closureCall), $resultSlot);
            $context->builder->branch($done);

            $context->builder->positionAtEnd($protoBb);
        }

        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $iterable, null)) {
            $context->builder->store(self::applyIteratorObject($context, $iterable, $closureCall), $resultSlot);
            $context->builder->branch($done);
            $context->builder->positionAtEnd($done);

            return $context->builder->load($resultSlot);
        }

        JitIterableArg::emitIterableTypeErrorForOperandAndAbort(
            $context,
            'iterator_apply',
            0,
            'iterator',
            $iterable,
            false
        );
        $context->builder->store($i64->constInt(0, false), $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    /**
     * iterator_apply over a packed `__spl_ht` snapshot (LimitIterator thin AOT).
     */
    private static function applyHashtable(Context $context, Variable $htVar, Call $closureCall): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ht = $context->helper->loadValue($htVar);
        $map = $context->structFieldMap['__hashtable__'];
        $nRaw = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $n = $context->builder->truncOrBitCast($nRaw, $i64);
        $countSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $countSlot);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('iterator_apply_ht_head');
        $body = $fn->appendBasicBlock('iterator_apply_ht_body');
        $done = $fn->appendBasicBlock('iterator_apply_ht_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $inRange = $context->builder->icmp(Builder::INT_SLT, $idx, $n);
        $context->builder->branchIf($inRange, $body, $done);
        $context->builder->positionAtEnd($body);
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $i64->constInt(1, false)),
            $countSlot
        );
        $idxSize = $context->builder->truncOrBitCast($idx, $sizeT);
        $value = HashTableHelper::readIndexedToValueBox($context, $ht, $idxSize);
        // Key = packed index for snapshot walks (reindexed InfiniteIterator tiles, #30273).
        $keySlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $keySlot, $idx);
        $key = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $keySlot);
        $result = $closureCall->call($context, $value, $key);
        $keep = IteratorProtocolHelper::truthyI1($context, $result);
        $advance = $fn->appendBasicBlock('iterator_apply_ht_advance');
        $context->builder->branchIf($keep, $advance, $done);
        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->add($idx, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }

    private static function applyIteratorObject(Context $context, Variable $iterable, Call $closureCall): Value
    {
        $receiver = IteratorProtocolHelper::normalizeObjectReceiver($context, $iterable);
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'rewind');
        $countSlot = $context->builder->alloca($context->getTypeFromString('int64'), 1, 'iterator_apply_obj_n');
        $context->builder->store(
            $context->getTypeFromString('int64')->constInt(0, false),
            $countSlot
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('iterator_apply_obj_head');
        $body = $fn->appendBasicBlock('iterator_apply_obj_body');
        $done = $fn->appendBasicBlock('iterator_apply_obj_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $valid = IteratorProtocolHelper::invokeIteratorMethodBool($context, $receiver, 'valid');
        $context->builder->branchIf($valid, $body, $done);
        $context->builder->positionAtEnd($body);
        // php-src — count each invoked iteration before checking callback (#25326).
        $cur = $context->builder->load($countSlot);
        $context->builder->store(
            $context->builder->add($cur, $context->getTypeFromString('int64')->constInt(1, false)),
            $countSlot
        );
        $value = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'current');
        $key = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'key');
        $result = $closureCall->call($context, $value, $key);
        $keep = IteratorProtocolHelper::truthyI1($context, $result);
        $advance = $fn->appendBasicBlock('iterator_apply_obj_advance');
        $context->builder->branchIf($keep, $advance, $done);
        $context->builder->positionAtEnd($advance);
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'next');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($countSlot);
    }
}
