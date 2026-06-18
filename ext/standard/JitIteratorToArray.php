<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\IteratorHelper;
use PHPCompiler\JIT\IteratorProtocolHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT lowering for iterator_to_array() (issue #3179, php-src ext/spl/iterator.c).
 */
final class JitIteratorToArray
{
    public static function invoke(Context $context, Variable $iterator, bool $preserveKeys): Value
    {
        return self::wrapHashTable($context, self::materializeHashtable($context, $iterator, $preserveKeys));
    }

    public static function invokeWithPreserveKeysFlag(Context $context, Variable $iterator, Value $preserveKeys): Value
    {
        $preserveBlock = BasicBlockHelper::append($context, 'ita_preserve_keys');
        $reindexBlock = BasicBlockHelper::append($context, 'ita_reindex_keys');
        $doneBlock = BasicBlockHelper::append($context, 'ita_preserve_keys_done');
        $context->builder->branchIf($preserveKeys, $preserveBlock, $reindexBlock);

        $context->builder->positionAtEnd($preserveBlock);
        $preserveResult = self::invoke($context, $iterator, true);
        $preserveEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($reindexBlock);
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
        GeneratorHelper::ensureTypes($context);
        $gen = self::resolveGenerator($context, $iterator);
        if (null !== $gen) {
            return self::materializeFromGenerator($context, $gen, $preserveKeys);
        }
        if (IteratorProtocolHelper::canLowerIteratorProtocol($context, $iterator, $containerUserType)) {
            return self::materializeFromIteratorProtocol($context, $iterator, $containerUserType);
        }

        return self::materializeFromArray($context, $iterator, $preserveKeys);
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
        if (GeneratorHelper::isGeneratorVariable($iterator)) {
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
        if (null === $gen->generatorStatePtr || null === $gen->generatorResumeName) {
            throw new \LogicException('iterator_to_array() requires a JIT Generator in this compiler build');
        }
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
        $state = $gen->generatorStatePtr;
        $map = $context->structFieldMap['__generator_state__'];
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $resumeFn = $context->functions[strtolower($gen->generatorResumeName)] ?? null;
        if (!$resumeFn instanceof Value\Function_) {
            throw new \LogicException('Generator resume function missing for iterator_to_array: '.$gen->generatorResumeName);
        }
        $fn = $context->builder->getInsertBlock()->getParent();
        $head = $fn->appendBasicBlock('ita_gen_head');
        $body = $fn->appendBasicBlock('ita_gen_body');
        $append = $fn->appendBasicBlock('ita_gen_append');
        $advance = $fn->appendBasicBlock('ita_gen_advance');
        $done = $fn->appendBasicBlock('ita_gen_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $doneFlag = $context->builder->load($context->builder->structGep($state, $map['done']));
        $context->builder->branchIf($doneFlag, $done, $body);

        $context->builder->positionAtEnd($body);
        $yielded = $context->builder->call($resumeFn, $state);
        $hasYield = $context->builder->icmp(Builder::INT_NE, $yielded, $i64->constInt(0, false));
        $context->builder->branchIf($hasYield, $append, $advance);

        $context->builder->positionAtEnd($append);
        $value = GeneratorHelper::compileIterValue($context, $gen);
        if ($preserveKeys) {
            $key = GeneratorHelper::compileIterKey($context, $gen);
            HashTableHelper::addElement($context, $out, $value, $key);
        } else {
            HashTableHelper::addElement($context, $out, $value, null);
        }
        $context->builder->branch($head);

        $context->builder->positionAtEnd($advance);
        $stillDone = $context->builder->load($context->builder->structGep($state, $map['done']));
        $context->builder->branchIf($stillDone, $done, $head);

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
