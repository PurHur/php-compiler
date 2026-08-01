<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\IncludePathRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for file() — file_get_contents + line split (issue #3765).
 *
 * Missing paths use {@see __compiler_file_get_contents} null (same as readfile false path).
 */
final class JitFile
{
    private const FILE_USE_INCLUDE_PATH = 1;
    private const FILE_IGNORE_NEW_LINES = 2;
    private const FILE_SKIP_EMPTY_LINES = 4;

    public static function invoke(Context $context, Value $pathStr, Value $flagsI64): Value
    {
        IncludePathRuntime::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $nullStr = $strPtrTy->constNull();

        $entryBlock = $context->builder->getInsertBlock();
        $useIncludePath = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flagsI64, $i64->constInt(self::FILE_USE_INCLUDE_PATH, false)),
            $zero
        );
        $resolveBlock = BasicBlockHelper::append($context, 'file_resolve_inc');
        $readPathBlock = BasicBlockHelper::append($context, 'file_read_path');
        $context->builder->branchIf($useIncludePath, $resolveBlock, $readPathBlock);

        $context->builder->positionAtEnd($resolveBlock);
        $resolved = $context->builder->call(
            $context->lookupFunction('__compiler_stream_resolve_include_path'),
            $pathStr
        );
        $hasResolved = $context->builder->icmp(Builder::INT_NE, $resolved, $nullStr);
        $useResolvedBlock = BasicBlockHelper::append($context, 'file_use_resolved');
        $context->builder->branchIf($hasResolved, $useResolvedBlock, $readPathBlock);

        $context->builder->positionAtEnd($useResolvedBlock);
        $context->builder->branch($readPathBlock);

        $context->builder->positionAtEnd($readPathBlock);
        $pathPhi = $context->builder->phi($strPtrTy, 'file_path');
        $pathPhi->addIncoming($pathStr, $entryBlock);
        $pathPhi->addIncoming($pathStr, $resolveBlock);
        $pathPhi->addIncoming($resolved, $useResolvedBlock);

        $exists = JitStat::pathExists($context, $pathPhi);
        $missing = $context->builder->icmp(Builder::INT_EQ, $exists, $i1->constInt(0, false));

        $failBlock = BasicBlockHelper::append($context, 'file_fail');
        $readBlock = BasicBlockHelper::append($context, 'file_read');
        $doneBlock = BasicBlockHelper::append($context, 'file_done');
        $context->builder->branchIf($missing, $failBlock, $readBlock);

        $context->builder->positionAtEnd($readBlock);
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $pathPhi
        );
        $readFailed = $context->builder->icmp(Builder::INT_EQ, $contents, $nullStr);
        $okBlock = BasicBlockHelper::append($context, 'file_ok');
        $context->builder->branchIf($readFailed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        // php-src file() open failure — same E_WARNING shape as fopen/file_get_contents (#26695)
        JitBuiltinWarning::emitStreamOpenFailed($context, $pathPhi, 'file');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $ht = self::splitLines($context, $contents, $flagsI64);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    /** @internal Shared with {@see JitGzfile} — gzfile() line split (#4657 phase 2). */
    public static function splitLines(Context $context, Value $contents, Value $flags): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $nl = $i8->constInt(10, false);
        $ignoreMask = $i64->constInt(self::FILE_IGNORE_NEW_LINES, false);
        $skipMask = $i64->constInt(self::FILE_SKIP_EMPTY_LINES, false);

        $len = $context->builder->load($context->builder->structGep($contents, $map['length']));
        $bytesPtr = $context->builder->pointerCast(
            $context->builder->structGep($contents, $map['value']),
            $context->getTypeFromString('int8*')
        );

        $ht = HashTableHelper::alloc($context);
        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $stringInit = $context->lookupFunction('__string__init');

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $posSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $startSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $startSlot);

        $emitBlock = BasicBlockHelper::append($context, 'file_emit');
        $afterEmit = BasicBlockHelper::append($context, 'file_after_emit');
        $loopHead = BasicBlockHelper::append($context, 'file_loop_head');
        $loopBody = BasicBlockHelper::append($context, 'file_loop_body');
        $loopDone = BasicBlockHelper::append($context, 'file_loop_done');
        $retBlock = BasicBlockHelper::append($context, 'file_ret');

        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $pos = $context->builder->load($posSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $pos, $len),
            $loopDone,
            $loopBody
        );

        $context->builder->positionAtEnd($loopBody);
        $byte = $context->builder->load($context->builder->inBoundsGEP($bytesPtr, $pos));
        $isNl = $context->builder->icmp(Builder::INT_EQ, $byte, $nl);
        $advanceBlock = BasicBlockHelper::append($context, 'file_advance');
        $context->builder->branchIf($isNl, $emitBlock, $advanceBlock);

        $context->builder->positionAtEnd($emitBlock);
        $start = $context->builder->load($startSlot);
        $ignore = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $ignoreMask),
            $zero
        );
        $endWithNl = $context->builder->add($pos, $one);
        $end = $context->builder->select($ignore, $pos, $endWithNl);
        $lineLen = $context->builder->sub($end, $start);
        $skip = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $skipMask),
            $zero
        );
        $skipCheck = BasicBlockHelper::append($context, 'file_skip_check');
        $appendLine = BasicBlockHelper::append($context, 'file_append_line');
        $context->builder->branchIf($skip, $skipCheck, $appendLine);
        $context->builder->positionAtEnd($skipCheck);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $lineLen, $zero),
            $afterEmit,
            $appendLine
        );
        $context->builder->positionAtEnd($appendLine);
        $linePtr = $context->builder->inBoundsGEP($bytesPtr, $start);
        $lineStr = $context->builder->call($stringInit, $lineLen, $linePtr);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setString, $ht, $idx, $lineStr);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $sizeT->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($afterEmit);

        $context->builder->positionAtEnd($afterEmit);
        $context->builder->store($context->builder->add($pos, $one), $startSlot);
        $context->builder->store($context->builder->add($pos, $one), $posSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($advanceBlock);
        $context->builder->store($context->builder->add($pos, $one), $posSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $start = $context->builder->load($startSlot);
        $hasTail = $context->builder->icmp(Builder::INT_SLT, $start, $len);
        $tailBlock = BasicBlockHelper::append($context, 'file_tail');
        $context->builder->branchIf($hasTail, $tailBlock, $retBlock);
        $context->builder->positionAtEnd($tailBlock);
        $lineLen = $context->builder->sub($len, $start);
        $skip = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $skipMask),
            $zero
        );
        $tailSkipCheck = BasicBlockHelper::append($context, 'file_tail_skip');
        $tailAppend = BasicBlockHelper::append($context, 'file_tail_append');
        $context->builder->branchIf($skip, $tailSkipCheck, $tailAppend);
        $context->builder->positionAtEnd($tailSkipCheck);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $lineLen, $zero),
            $retBlock,
            $tailAppend
        );
        $context->builder->positionAtEnd($tailAppend);
        $linePtr = $context->builder->inBoundsGEP($bytesPtr, $start);
        $lineStr = $context->builder->call($stringInit, $lineLen, $linePtr);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setString, $ht, $idx, $lineStr);
        $context->builder->branch($retBlock);

        $context->builder->positionAtEnd($retBlock);
        BasicBlockHelper::branchToFreshContinue($context, 'file_continue');

        return $ht;
    }
}
