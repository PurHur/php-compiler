<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\boolval;
use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM array_filter(Closure) for thin standalone AOT (#32672 peer ArrayMapLlvm / UsortPackedLlvm).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\ArrayFilterJitHelper} declines callback forms under
 * thin AOT; iterate packed slots and invoke the Closure via {@see NestedClosureInvoke}.
 * Packed walks skip TYPE_UNDEFINED only — TYPE_NULL is kept when the callback returns truthy (#33705).
 *
 * php-src: ext/standard/array.c — php_array_filter()
 */
final class ArrayFilterLlvm
{
    /**
     * Default {@see ARRAY_FILTER_USE_VALUE}: keep elements where callback($value) is truthy.
     */
    public static function filterPackedWithClosure(Context $context, Value $src, Variable $closure): Value
    {
        if (null === $closure->closureCall && [] === \PHPCompiler\VM\VmClosure::closureCandidates($context)) {
            NestedClosureInvokeLlvm::ensureLinked($context);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'array_filter_llvm_cont');

        return self::filterPacked($context, $src, $closure, 'array_filter_closure');
    }

    private static function filterPacked(
        Context $context,
        Value $src,
        Variable $closure,
        string $prefix
    ): Value {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $map['nextFreeElement'])
        );
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $nextFree, $zero);
        $emptyBlock = BasicBlockHelper::append($context, $prefix.'_empty');
        $workBlock = BasicBlockHelper::append($context, $prefix.'_work');
        $doneBlock = BasicBlockHelper::append($context, $prefix.'_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyHt = HashTableHelper::alloc($context);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = HashTableHelper::alloc($context);
        $srcIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $srcIdxSlot);
        $head = BasicBlockHelper::append($context, $prefix.'_head');
        $check = BasicBlockHelper::append($context, $prefix.'_check');
        $filterBlock = BasicBlockHelper::append($context, $prefix.'_filter');
        $skip = BasicBlockHelper::append($context, $prefix.'_skip');
        $keep = BasicBlockHelper::append($context, $prefix.'_keep');
        $advance = BasicBlockHelper::append($context, $prefix.'_advance');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $srcIdx = $context->builder->load($srcIdxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $srcIdx, $nextFree);
        $context->builder->branchIf($atEnd, $doneBlock, $check);

        $context->builder->positionAtEnd($check);
        // Skip TYPE_UNDEFINED holes only — TYPE_NULL is a real value (#33705 / #33699).
        $isUndef = HashTableHelper::packedIndexIsUndefined($context, $src, $srcIdx);
        $context->builder->branchIf($isUndef, $skip, $filterBlock);

        $context->builder->positionAtEnd($filterBlock);
        $elem = HashTableHelper::readIndexedToValueBox($context, $src, $srcIdx);
        $raw = (new NestedClosureInvoke())->call($context, $closure, $elem);
        $resultPtr = JitNestedHelperCoerce::valueBoxPtrFromHelperResult($context, $raw);
        $truthy = boolval::boxedTruthyScalar($context, $resultPtr);
        $context->builder->branchIf($truthy, $keep, $skip);

        $context->builder->positionAtEnd($keep);
        HashTableHelper::setAtIndex($context, $dest, $srcIdx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->addNoSignedWrap($srcIdx, $one),
            $srcIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($emptyHt->typeOf());
        $phi->addIncoming($emptyHt, $emptyBlock);
        $phi->addIncoming($dest, $head);

        return $phi;
    }
}
