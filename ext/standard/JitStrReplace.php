<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helper for str_replace() — JitStringSearch loop with slice + concat (#4146, #14017).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrReplace
{
    private static int $blockSerial = 0;

    public static function replace(
        Context $context,
        Value $search,
        Value $replace,
        Value $subject,
        bool $caseInsensitive = false,
        ?Value $countSlot = null
    ): Value {
        $id = (string) (++self::$blockSerial);
        $map = $context->structFieldMap['__string__'];
        $searchLen = $context->builder->load(
            $context->builder->structGep($search, $map['length'])
        );
        $subjectLen = $context->builder->load(
            $context->builder->structGep($subject, $map['length'])
        );
        $subjectPtr = $context->builder->structGep($subject, $map['value']);

        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $tag = $caseInsensitive ? 'ireplace' : 'replace';
        $resultSlot = $context->builder->alloca(
            $context->getTypeFromString('__string__*'),
            1,
            'str_'.$tag.'_result'
        );
        $offsetSlot = $context->builder->alloca($i64, 1, 'str_'.$tag.'_offset');
        $emptyResult = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->store($emptyResult, $resultSlot);
        $context->builder->store($zero, $offsetSlot);

        $loopHead = BasicBlockHelper::append($context, 'str_'.$tag.'_head_'.$id);
        $loopBody = BasicBlockHelper::append($context, 'str_'.$tag.'_body_'.$id);
        $tailBlock = BasicBlockHelper::append($context, 'str_'.$tag.'_tail_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'str_'.$tag.'_done_'.$id);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $offset = $context->builder->load($offsetSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $offset, $subjectLen);
        $context->builder->branchIf($pastEnd, $doneBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $offset = $context->builder->load($offsetSlot);
        $foundI32 = JitStringSearch::findOffsetI32(
            $context,
            $subject,
            $search,
            $offset,
            $caseInsensitive
        );
        $i32 = $context->getTypeFromString('int32');
        $notFound = $context->builder->icmp(
            Builder::INT_EQ,
            $foundI32,
            $i32->constInt(JitStringSearch::NOT_FOUND, true)
        );
        $matchBlock = BasicBlockHelper::append($context, 'str_'.$tag.'_match_'.$id);
        $context->builder->branchIf($notFound, $tailBlock, $matchBlock);

        $context->builder->positionAtEnd($matchBlock);
        $pos = $context->builder->zExt($foundI32, $i64);
        $prefixLen = $context->builder->sub($pos, $offset);
        $prefix = string_trim::jitCopySlice($context, $subject, $subjectPtr, $offset, $prefixLen);
        $acc = $context->builder->load($resultSlot);
        $withPrefix = JitStringConcat::concat($context, $acc, $prefix);
        $withReplace = JitStringConcat::concat($context, $withPrefix, $replace);
        $context->builder->store($withReplace, $resultSlot);
        if (null !== $countSlot) {
            $one = $i64->constInt(1, false);
            $context->builder->store(
                $context->builder->addNoSignedWrap($context->builder->load($countSlot), $one),
                $countSlot
            );
        }
        $newOffset = $context->builder->add($pos, $searchLen);
        $context->builder->store($newOffset, $offsetSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($tailBlock);
        $offset = $context->builder->load($offsetSlot);
        $tailLen = $context->builder->sub($subjectLen, $offset);
        $tail = string_trim::jitCopySlice($context, $subject, $subjectPtr, $offset, $tailLen);
        $acc = $context->builder->load($resultSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $tail), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }
}
