<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helper for str_replace() — strstr loop with slice + concat (byte-safe subset).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrReplace
{
    public static function replace(
        Context $context,
        Value $search,
        Value $replace,
        Value $subject,
        bool $caseInsensitive = false
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $searchLen = $context->builder->load(
            $context->builder->structGep($search, $map['length'])
        );
        $subjectLen = $context->builder->load(
            $context->builder->structGep($subject, $map['length'])
        );
        $subjectPtr = $context->builder->structGep($subject, $map['value']);
        $searchPtr = $context->builder->structGep($search, $map['value']);

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

        $loopHead = BasicBlockHelper::append($context, 'str_'.$tag.'_head');
        $loopBody = BasicBlockHelper::append($context, 'str_'.$tag.'_body');
        $tailBlock = BasicBlockHelper::append($context, 'str_'.$tag.'_tail');
        $doneBlock = BasicBlockHelper::append($context, 'str_'.$tag.'_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $offset = $context->builder->load($offsetSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_SGE, $offset, $subjectLen);
        $context->builder->branchIf($pastEnd, $doneBlock, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $searchFrom = $context->builder->gep($subjectPtr, $offset);
        $searchFn = $caseInsensitive ? 'strcasestr' : 'strstr';
        $found = $context->builder->call(
            $context->lookupFunction($searchFn),
            $searchFrom,
            $searchPtr
        );
        $null = $context->getTypeFromString('int8*')->constNull();
        $notFound = $context->builder->icmp(Builder::INT_EQ, $found, $null);
        $matchBlock = BasicBlockHelper::append($context, 'str_'.$tag.'_match');
        $context->builder->branchIf($notFound, $tailBlock, $matchBlock);

        $context->builder->positionAtEnd($matchBlock);
        $foundInt = $context->builder->ptrToInt($found, $i64);
        $baseInt = $context->builder->ptrToInt($subjectPtr, $i64);
        $pos = $context->builder->sub($foundInt, $baseInt);
        $prefixLen = $context->builder->sub($pos, $offset);
        $prefix = string_trim::jitCopySlice($context, $subject, $subjectPtr, $offset, $prefixLen);
        $acc = $context->builder->load($resultSlot);
        $withPrefix = JitStringConcat::concat($context, $acc, $prefix);
        $withReplace = JitStringConcat::concat($context, $withPrefix, $replace);
        $context->builder->store($withReplace, $resultSlot);
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
