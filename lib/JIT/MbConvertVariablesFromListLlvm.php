<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\MbConvertEncodingRuntime;
use PHPCompiler\JIT\ExceptionBridge;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Pure LLVM mb_convert_variables() runtime array $from_encoding → CSV (#35315 leftover).
 *
 * Peer {@see MbConvertEncodingFromListLlvm} — NestedJIT cannot build PHP arrays under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_variables)
 */
final class MbConvertVariablesFromListLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    public static function buildFromCsv(Context $context, Variable $fromArg): Value
    {
        MbConvertEncodingRuntime::ensureLinked($context);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mcv_from_list');

        $ht = ArrayBuiltinHelper::isNativeArray($fromArg->type)
            ? ArrayBuiltinHelper::nativeListToHashTable($context, $fromArg)
            : ArrayBuiltinHelper::loadHashTable($context, $fromArg);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        // nextFreeElement can trail packed append slots under AOT; numElements matches Zend list length.
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );

        $tag = (string) self::nextSeq();
        $emptyBb = BasicBlockHelper::append($context, 'mcv_fl_empty_'.$tag);
        $workBb = BasicBlockHelper::append($context, 'mcv_fl_work_'.$tag);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $count, $zero);
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        ExceptionBridge::ensureLinked($context);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'mb_convert_variables(): Argument #2 ($from_encoding) contains invalid encoding ""'
        );

        $context->builder->positionAtEnd($workBb);
        $csvSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->store($emptyStr, $csvSlot);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $writtenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $writtenSlot);

        $head = BasicBlockHelper::append($context, 'mcv_fl_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'mcv_fl_body_'.$tag);
        $issetWork = BasicBlockHelper::append($context, 'mcv_fl_isset_'.$tag);
        $append = BasicBlockHelper::append($context, 'mcv_fl_append_'.$tag);
        $next = BasicBlockHelper::append($context, 'mcv_fl_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'mcv_fl_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isUndefined = HashTableReadLlvm::packedIndexIsUndefined($context, $ht, $idx);
        $context->builder->branchIf($isUndefined, $next, $issetWork);

        $context->builder->positionAtEnd($issetWork);
        $encPtr = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringAt'),
            $ht,
            $idx
        );
        $context->builder->call(
            MbConvertEncodingRuntime::assertFromEncodingHelper($context),
            $encPtr
        );
        $context->builder->branch($append);

        $context->builder->positionAtEnd($append);
        $written = $context->builder->load($writtenSlot);
        $needsComma = $context->builder->icmp(Builder::INT_SGT, $written, $zero);
        $commaBb = BasicBlockHelper::append($context, 'mcv_fl_comma_'.$tag);
        $joinBb = BasicBlockHelper::append($context, 'mcv_fl_join_'.$tag);
        $context->builder->branchIf($needsComma, $commaBb, $joinBb);

        $context->builder->positionAtEnd($commaBb);
        $csvBefore = $context->builder->load($csvSlot);
        $comma = $context->builder->load($context->constantStringFromString(','));
        $withComma = JitStringConcat::concat($context, $csvBefore, $comma, false);
        $context->builder->store($withComma, $csvSlot);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);
        $csvCur = $context->builder->load($csvSlot);
        $ownedEnc = $context->builder->call($context->lookupFunction('__string__separate'), $encPtr);
        $withEnc = JitStringConcat::concat($context, $csvCur, $ownedEnc, false);
        $context->builder->store($withEnc, $csvSlot);
        $context->builder->store($context->builder->addNoSignedWrap($written, $one), $writtenSlot);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $built = $context->builder->load($csvSlot);
        $builtCount = $context->builder->load($writtenSlot);
        $stillEmpty = $context->builder->icmp(Builder::INT_EQ, $builtCount, $zero);
        $emptyAfterBb = BasicBlockHelper::append($context, 'mcv_fl_empty_after_'.$tag);
        $okBb = BasicBlockHelper::append($context, 'mcv_fl_ok_'.$tag);
        $context->builder->branchIf($stillEmpty, $emptyAfterBb, $okBb);

        $context->builder->positionAtEnd($emptyAfterBb);
        ExceptionBridge::ensureLinked($context);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'mb_convert_variables(): Argument #2 ($from_encoding) contains invalid encoding ""'
        );

        $context->builder->positionAtEnd($okBb);

        return $built;
    }
}
