<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helper for str_replace() — JitStringSearch loop with slice + concat.
 *
 * Restored for user-script AOT (#23912): NestedJIT of StrReplaceJitHelper miscompiles
 * string indexing / length under thin AOT (wrong output or segfault). Keep the PHP helper
 * for SSOT / VM parity tests; scalar JIT/AOT lowering uses this LLVM path.
 *
 * php-src: ext/standard/string.c — php_str_replace
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
        JitStringSearch::ensureLinked($context);
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
        $strPtrTy = $context->getTypeFromString('__string__*');

        $tag = $caseInsensitive ? 'ireplace' : 'replace';
        // entryAlloca: mid-block alloca orphan stores under NestedJIT/user-script AOT (#20664).
        $resultSlot = BasicBlockHelper::entryAlloca($context, $strPtrTy);
        $offsetSlot = BasicBlockHelper::entryAlloca($context, $i64);
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
