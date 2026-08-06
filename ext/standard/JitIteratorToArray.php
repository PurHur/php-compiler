<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\IteratorHelper;
use PHPCompiler\JIT\IteratorProtocolHelper;
use PHPCompiler\JIT\JitIterableArg;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT lowering for iterator_to_array() (issue #3179, php-src ext/spl/iterator.c).
 *
 * Boxed `__value__` (e.g. `$x = null`) must TypeError before Iterator/Generator
 * protocol lowering — otherwise thin AOT hits `__generator_resume` (#27634).
 */
final class JitIteratorToArray
{
    public static function invoke(Context $context, Variable $iterator, bool $preserveKeys): Value
    {
        ExceptionBridge::ensureLinked($context);

        return self::wrapHashTable($context, self::materializeHashtable($context, $iterator, $preserveKeys));
    }

    public static function invokeWithPreserveKeysFlag(Context $context, Variable $iterator, Value $preserveKeys): Value
    {
        $preserveBlock = BasicBlockHelper::append($context, 'ita_preserve_keys');
        $reindexBlock = BasicBlockHelper::append($context, 'ita_reindex_keys');
        $doneBlock = BasicBlockHelper::append($context, 'ita_preserve_keys_done');
        $context->builder->branchIf($preserveKeys, $preserveBlock, $reindexBlock);

        // Each arm must inttoptr/load __generator_state__ in its own block. A cached
        // Value from the sibling arm fails Module->verify (dominate-uses, #26802).
        $context->builder->positionAtEnd($preserveBlock);
        $iterator->generatorStatePtr = null;
        $preserveResult = self::invoke($context, $iterator, true);
        $preserveEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($reindexBlock);
        $iterator->generatorStatePtr = null;
        $reindexResult = self::invoke($context, $iterator, false);
        $reindexEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($preserveResult->typeOf());
        $phi->addIncoming($preserveResult, $preserveEnd);
        $phi->addIncoming($reindexResult, $reindexEnd);

        return $phi;
    }

    /**
     * Materialize Traversable/array operand into __hashtable__* (array spread / iterator_to_array, #4453).
     */
    public static function materializeHashtable(
        Context $context,
        Variable $iterator,
        bool $preserveKeys,
        ?string $containerUserType = null
    ): Value {
        if (Variable::TYPE_NULL === $iterator->type || ($iterator->isNullConstant ?? false)) {
            JitIterableArg::emitIterableTypeErrorAndAbort(
                $context,
                'iterator_to_array',
                0,
                'iterator',
                'null'
            );

            return HashTableHelper::alloc($context);
        }
        if (Variable::TYPE_VALUE === $iterator->type || JitValueBox::isValueOperand($iterator)) {
            return self::materializeBoxedValue($context, $iterator, $preserveKeys, $containerUserType);
        }

        return self::materializeKnownTyped($context, $iterator, $preserveKeys, $containerUserType);
    }

    /**
     * Runtime tag dispatch for boxed operands — null/non-iterable TypeError before
     * Generator rewind proxies (#27634 / peer #27633).
     */
    private static function materializeBoxedValue(
        Context $context,
        Variable $arg,
        bool $preserveKeys,
        ?string $containerUserType
    ): Value {
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $i8 = $context->getTypeFromString('int8');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $htTag = $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false);
        $arrayTag = $i8->constInt(VmVariable::TYPE_ARRAY & 0x7f, false);
        $objTag = $i8->constInt(VmVariable::TYPE_OBJECT & 0x7f, false);
        $nullTag = $i8->constInt(VmVariable::TYPE_NULL & 0x7f, false);
        $isHt = $context->builder->icmp(Builder::INT_EQ, $kind, $htTag);
        $isArray = $context->builder->icmp(Builder::INT_EQ, $kind, $arrayTag);
        $isObj = $context->builder->icmp(Builder::INT_EQ, $kind, $objTag);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $kind, $nullTag);

        $resultSlot = BasicBlockHelper::entryAlloca($context, $htPtrTy);
        $htBb = BasicBlockHelper::append($context, 'ita_box_ht');
        $afterHt = BasicBlockHelper::append($context, 'ita_box_after_ht');
        $arrBb = BasicBlockHelper::append($context, 'ita_box_array');
        $afterArr = BasicBlockHelper::append($context, 'ita_box_after_array');
        $objBb = BasicBlockHelper::append($context, 'ita_box_obj');
        $afterObj = BasicBlockHelper::append($context, 'ita_box_after_obj');
        $nullBb = BasicBlockHelper::append($context, 'ita_box_null');
        $badBb = BasicBlockHelper::append($context, 'ita_box_bad');
        $done = BasicBlockHelper::append($context, 'ita_box_done');

        $context->builder->branchIf($isHt, $htBb, $afterHt);

        $context->builder->positionAtEnd($htBb);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $htVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $ht);
        $context->builder->store(
            self::materializeFromArray($context, $htVar, $preserveKeys),
            $resultSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterHt);
        $context->builder->branchIf($isArray, $arrBb, $afterArr);

        $context->builder->positionAtEnd($arrBb);
        // TYPE_ARRAY boxes still expose an HT via the same reader as hashtable (#27634).
        $arrHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valuePtr
        );
        $arrVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $arrHt);
        $context->builder->store(
            self::materializeFromArray($context, $arrVar, $preserveKeys),
            $resultSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterArr);
        $context->builder->branchIf($isObj, $objBb, $afterObj);

        $context->builder->positionAtEnd($objBb);
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        $context->builder->store(
            self::materializeKnownTyped($context, $objVar, $preserveKeys, $containerUserType),
            $resultSlot
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterObj);
        $context->builder->branchIf($isNull, $nullBb, $badBb);

        $context->builder->positionAtEnd($nullBb);
        JitIterableArg::emitIterableTypeErrorAndAbort(
            $context,
            'iterator_to_array',
            0,
            'iterator',
            'null'
        );
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($badBb);
        JitIterableArg::emitIterableTypeErrorAndAbort(
            $context,
            'iterator_to_array',
            0,
            'iterator',
            JitOperandTypeLabel::givenLabel($context, $arg)
        );
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    private static function materializeKnownTyped(
        Context $context,
        Variable $iterator,
        bool $preserveKeys,
        ?string $containerUserType = null
    ): Value {
        $opUserType = $context->jitIteratorToArrayIteratorOperand?->type?->userType ?? null;
        $userType = $containerUserType
            ?? (is_string($opUserType) && '' !== $opUserType ? $opUserType : null)
            ?? $iterator->userType
            ?? $iterator->classUserType
            ?? (Variable::TYPE_OBJECT === $iterator->type
                ? ($iterator->compileTimeString ?? $iterator->objectPropertyClassName)
                : null);
        if (\PHPCompiler\VM\SplOuterIteratorHt::isHtBacked($userType)) {
            return self::materializeFromSplHt($context, $iterator, $preserveKeys);
        }
        GeneratorHelper::ensureTypes($context);
        $gen = self::resolveGenerator($context, $iterator);
        if (null !== $gen) {
            return self::materializeFromGenerator($context, $gen, $preserveKeys);
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $iterator, $userType)) {
            return self::materializeFromIteratorProtocol($context, $iterator, $userType);
        }

        return self::materializeFromArray($context, $iterator, $preserveKeys);
    }

    private static function materializeFromSplHt(
        Context $context,
        Variable $iterator,
        bool $preserveKeys
    ): Value {
        $receiver = IteratorProtocolHelper::normalizeObjectReceiver($context, $iterator);
        $userType = $context->jitIteratorToArrayIteratorOperand?->type?->userType
            ?? $iterator->classUserType
            ?? $iterator->compileTimeString
            ?? 'ArrayIterator';
        $className = ltrim((string) $userType, '\\');
        if ('' === $className || 'object' === strtolower($className)) {
            $className = 'ArrayIterator';
        }
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            $className,
            \PHPCompiler\VM\SplOuterIteratorHt::PROP_HT
        );
        $srcHt = $context->builder->pointerCast(
            $context->builder->load($slot),
            $context->getTypeFromString('__hashtable__*')
        );
        if ($preserveKeys && self::classUsesParallelSplKeys($className)) {
            return self::materializeFromSplHtParallelKeys($context, $receiver, $className, $srcHt);
        }
        if ($preserveKeys) {
            return self::copyHashtablePreserveKeys($context, $srcHt);
        }
        $out = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        $out->nextFreeElement = 0;
        self::reindexHashtable($context, $out, $srcHt);

        return $context->helper->loadValue($out);
    }

    /**
     * AppendIterator / RecursiveIteratorIterator store original keys in `__spl_keys` (#27312, #27257).
     */
    private static function classUsesParallelSplKeys(string $className): bool
    {
        $lc = strtolower(ltrim($className, '\\'));

        return 'appenditerator' === $lc || 'recursiveiteratoriterator' === $lc;
    }

    /**
     * Rebuild array from packed values + parallel original keys (Zend ITA overwrite order).
     */
    private static function materializeFromSplHtParallelKeys(
        Context $context,
        Variable $receiver,
        string $className,
        Value $valuesHt
    ): Value {
        $lc = strtolower(ltrim($className, '\\'));
        $keysHtVar = 'appenditerator' === $lc
            ? \PHPCompiler\JIT\Call\AppendIteratorMethod::keysHashtable($context, $receiver)
            : \PHPCompiler\JIT\Call\RecursiveIteratorIteratorConstruct::keysHashtable($context, $receiver);
        $keysHt = $context->helper->loadValue($keysHtVar);
        $out = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($valuesHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('ita_spl_keys_head');
        $body = $fn->appendBasicBlock('ita_spl_keys_body');
        $done = $fn->appendBasicBlock('ita_spl_keys_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $value = HashTableHelper::readIndexedToValueBox($context, $valuesHt, $idx);
        $key = HashTableHelper::readIndexedToValueBox($context, $keysHt, $idx);
        HashTableHelper::addElement($context, $out, $value, $key);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->refcount->addref($context->helper->loadValue($out));

        return $context->helper->loadValue($out);
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
        $context->refcount->addref($ht);

        return $ptr;
    }

    private static function resolveGenerator(Context $context, Variable $iterator): ?Variable
    {
        if (GeneratorHelper::hydrateGeneratorMetadata($context, $iterator)) {
            return $iterator;
        }

        return null;
    }

    private static function materializeFromIteratorProtocol(
        Context $context,
        Variable $iterator,
        ?string $containerUserType
    ): Value {
        $receiver = IteratorProtocolHelper::resolveForeachReceiver($context, $iterator, $containerUserType);
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'rewind', $containerUserType);
        $out = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('ita_iter_proto_head');
        $body = $fn->appendBasicBlock('ita_iter_proto_body');
        $advance = $fn->appendBasicBlock('ita_iter_proto_advance');
        $done = $fn->appendBasicBlock('ita_iter_proto_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $valid = IteratorProtocolHelper::invokeIteratorMethodBool(
            $context,
            $receiver,
            'valid',
            $containerUserType
        );
        $context->builder->branchIf($valid, $body, $done);

        $context->builder->positionAtEnd($body);
        $key = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'key', $containerUserType);
        $value = IteratorProtocolHelper::invokeIteratorMethodValue($context, $receiver, 'current', $containerUserType);
        HashTableHelper::addElement($context, $out, $value, $key);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        IteratorProtocolHelper::invokeIteratorMethod($context, $receiver, 'next', $containerUserType);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->helper->loadValue($out);
    }

    private static function materializeFromGenerator(
        Context $context,
        Variable $gen,
        bool $preserveKeys
    ): Value {
        // Drain via the same IterReset/Valid/Value path as foreach — the hand-rolled
        // ensureStarted + has_current loop segfaulted under AOT (#26802).
        GeneratorHelper::loadStateFromGeneratorObject($context, $gen);
        GeneratorHelper::compileIterReset($context, $gen);

        $out = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        if (!$preserveKeys) {
            $out->nextFreeElement = 0;
        }
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('ita_gen_head');
        $body = $fn->appendBasicBlock('ita_gen_body');
        $done = $fn->appendBasicBlock('ita_gen_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $valid = GeneratorHelper::compileIterValid($context, $gen);
        $context->builder->branchIf($valid, $body, $done);

        $context->builder->positionAtEnd($body);
        $value = GeneratorHelper::compileIterValue($context, $gen);
        if ($preserveKeys) {
            $key = GeneratorHelper::compileIterKey($context, $gen);
            HashTableHelper::addElement($context, $out, $value, $key);
        } else {
            HashTableHelper::addElement($context, $out, $value, null);
        }
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->helper->loadValue($out);
    }

    private static function materializeFromArray(
        Context $context,
        Variable $iterator,
        bool $preserveKeys
    ): Value {
        if ($iterator->type & Variable::IS_NATIVE_ARRAY) {
            $ht = HashTableHelper::materializeNativeArrayForCall($context, $iterator);
            if ($preserveKeys) {
                return $ht;
            }
            $out = new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                HashTableHelper::alloc($context)
            );
            $out->nextFreeElement = 0;
            self::reindexHashtable($context, $out, $ht);

            return $context->helper->loadValue($out);
        }
        $src = HashTableHelper::coerceToPackedHashtable($context, $iterator);
        if ($preserveKeys) {
            return self::copyHashtablePreserveKeys($context, $context->helper->loadValue($src));
        }
        $out = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        $out->nextFreeElement = 0;
        self::reindexViaIterator($context, $out, $src);

        return $context->helper->loadValue($out);
    }

    private static function copyHashtablePreserveKeys(Context $context, Value $srcHt): Value
    {
        $dest = HashTableHelper::alloc($context);
        $destVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $dest);
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($srcHt, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'ita_copy_idx');
        $context->builder->store($zero, $idxSlot);
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('ita_copy_packed_head');
        $body = $fn->appendBasicBlock('ita_copy_packed_body');
        $advance = $fn->appendBasicBlock('ita_copy_packed_advance');
        $done = $fn->appendBasicBlock('ita_copy_packed_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $srcHt,
            $idx
        );
        $skip = $fn->appendBasicBlock('ita_copy_packed_skip');
        $copy = $fn->appendBasicBlock('ita_copy_packed_copy');
        $context->builder->branchIf($isSet, $copy, $skip);

        $context->builder->positionAtEnd($copy);
        $elem = HashTableHelper::readIndexedToValueBox($context, $srcHt, $idx);
        HashTableHelper::setAtIndex($context, $dest, $idx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        self::copyStringKeysPreserve($context, $destVar, $srcHt);
        $context->refcount->addref($dest);

        return $dest;
    }

    private static function copyStringKeysPreserve(Context $context, Variable $dest, Value $srcHt): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('ita_copy_str_head');
        $body = $fn->appendBasicBlock('ita_copy_str_body');
        $advance = $fn->appendBasicBlock('ita_copy_str_advance');
        $done = $fn->appendBasicBlock('ita_copy_str_done');
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($srcHt, $map['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyVar = new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $keyStr);
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        HashTableHelper::addElement($context, $dest, $elem, $keyVar);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function reindexHashtable(Context $context, Variable $dest, Value $srcHt): void
    {
        $slot = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $srcHt);
        self::reindexViaIterator($context, $dest, $slot);
    }

    private static function reindexViaIterator(Context $context, Variable $dest, Variable $src): void
    {
        IteratorHelper::compileReset($context, $src, null);
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('ita_iter_head');
        $body = $fn->appendBasicBlock('ita_iter_body');
        $done = $fn->appendBasicBlock('ita_iter_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $valid = IteratorHelper::compileValid($context, $src, null);
        $context->builder->branchIf($valid, $body, $done);

        $context->builder->positionAtEnd($body);
        $value = IteratorHelper::compileValue($context, $src, null);
        HashTableHelper::addElement($context, $dest, $value, null);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
