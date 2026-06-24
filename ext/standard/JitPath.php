<?php

declare(strict_types=1);

/**
 * LLVM JIT helpers for dirname() and basename() (byte paths, / and \ separators).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitPath
{
    private static int $blockSerial = 0;

    public static function dirname(Context $context, Value $str): Value
    {
        $id = (string) (++self::$blockSerial);
        [$len, $charPtr] = self::stringFields($context, $str);
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);
        $minusOne = $i64->constInt(-1, false);

        $done = self::block($context, 'dirname_done_'.$id);
        $nonEmpty = self::block($context, 'dirname_nonempty_'.$id);
        $emptyInput = self::block($context, 'dirname_empty_input_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $len, $zero),
            $emptyInput,
            $nonEmpty
        );

        $context->builder->positionAtEnd($emptyInput);
        $emptyStr = self::loadLiteral($context, '');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($nonEmpty);
        $endSlot = $context->builder->alloca($i64, 1, 'dirname_end');
        $context->builder->store($len, $endSlot);
        self::trimTrailingSeparators($context, $charPtr, $endSlot, $id);

        $end = $context->builder->load($endSlot);
        $trimmedEmpty = self::block($context, 'dirname_trimmed_empty_'.$id);
        $scanBlock = self::block($context, 'dirname_scan_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $end, $zero),
            $trimmedEmpty,
            $scanBlock
        );

        $context->builder->positionAtEnd($trimmedEmpty);
        $rootFromTrim = self::rootOrDot($context, $charPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($scanBlock);
        $schemeSepSlot = $context->builder->alloca($i64, 1, 'dirname_scheme_sep');
        $context->builder->store($minusOne, $schemeSepSlot);
        self::findSchemeSepIndex($context, $charPtr, $end, $schemeSepSlot, $id);
        $schemeSep = $context->builder->load($schemeSepSlot);
        $hasScheme = $context->builder->icmp(Builder::INT_SGE, $schemeSep, $zero);
        $minSepSlot = $context->builder->alloca($i64, 1, 'dirname_min_sep');
        $three = $i64->constInt(3, false);
        $afterScheme = $context->builder->add($schemeSep, $three);
        $context->builder->store(
            $context->builder->select($hasScheme, $afterScheme, $zero),
            $minSepSlot
        );

        $lastSlot = $context->builder->alloca($i64, 1, 'dirname_last_sep');
        $context->builder->store($minusOne, $lastSlot);
        $idxSlot = $context->builder->alloca($i64, 1, 'dirname_idx');
        $context->builder->store($context->builder->sub($end, $one), $idxSlot);
        self::scanBackwardForSeparator($context, $charPtr, $idxSlot, $lastSlot, $minSepSlot, $id);

        $last = $context->builder->load($lastSlot);
        $noSep = self::block($context, 'dirname_no_sep_'.$id);
        $atRoot = self::block($context, 'dirname_at_root_'.$id);
        $sliceBlock = self::block($context, 'dirname_slice_'.$id);
        $wrapperRoot = self::block($context, 'dirname_wrapper_root_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $last, $zero),
            $noSep,
            $atRoot
        );

        $context->builder->positionAtEnd($noSep);
        $noSepDot = self::block($context, 'dirname_no_sep_dot_'.$id);
        $context->builder->branchIf($hasScheme, $wrapperRoot, $noSepDot);

        $context->builder->positionAtEnd($noSepDot);
        $dotResult = self::loadLiteral($context, '.');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($wrapperRoot);
        $schemeRootLen = $context->builder->add($schemeSep, $one);
        $wrapperRootResult = string_trim::jitCopySlice($context, $str, $charPtr, $zero, $schemeRootLen, $id.'_wrapper');
        $wrapperRootDoneBlock = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($atRoot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $last, $zero),
            $trimmedEmpty,
            $sliceBlock
        );

        $context->builder->positionAtEnd($sliceBlock);
        $sliceResult = string_trim::jitCopySlice($context, $str, $charPtr, $zero, $last, $id);
        $sliceDoneBlock = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($str->typeOf());
        $phi->addIncoming($emptyStr, $emptyInput);
        $phi->addIncoming($rootFromTrim, $trimmedEmpty);
        $phi->addIncoming($dotResult, $noSepDot);
        $phi->addIncoming($wrapperRootResult, $wrapperRootDoneBlock);
        $phi->addIncoming($sliceResult, $sliceDoneBlock);

        return $phi;
    }

    public static function dirnameWithLevels(Context $context, Value $path, Value $levels): Value
    {
        JitDirname::emitRuntimeLevelsGuard($context, $levels);

        $id = (string) (++self::$blockSerial);
        $i64 = JitStringIndex::i64($context);
        $one = $i64->constInt(1, false);
        $zero = JitStringIndex::zero($context);

        $resultSlot = $context->builder->alloca($path->typeOf(), 1, 'dirname_levels_result');
        $context->builder->store($path, $resultSlot);
        $counterSlot = $context->builder->alloca($i64, 1, 'dirname_levels_counter');
        $context->builder->store($levels, $counterSlot);

        $head = self::block($context, 'dirname_levels_head_'.$id);
        $body = self::block($context, 'dirname_levels_body_'.$id);
        $done = self::block($context, 'dirname_levels_done_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $counter = $context->builder->load($counterSlot);
        $stop = $context->builder->icmp(Builder::INT_SLE, $counter, $zero);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $current = $context->builder->load($resultSlot);
        $next = self::dirname($context, $current);
        $context->builder->store($next, $resultSlot);
        $context->builder->store($context->builder->sub($counter, $one), $counterSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($resultSlot);
    }

    public static function basename(Context $context, Value $str): Value
    {
        $id = (string) (++self::$blockSerial);
        [$len, $charPtr] = self::stringFields($context, $str);
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);
        $minusOne = $i64->constInt(-1, false);

        $done = self::block($context, 'basename_done_'.$id);
        $emptyInput = self::block($context, 'basename_empty_input_'.$id);
        $nonEmpty = self::block($context, 'basename_nonempty_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $len, $zero),
            $emptyInput,
            $nonEmpty
        );

        $context->builder->positionAtEnd($emptyInput);
        $emptyStr = self::loadLiteral($context, '');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($nonEmpty);
        $endSlot = $context->builder->alloca($i64, 1, 'basename_end');
        $context->builder->store($len, $endSlot);
        self::trimTrailingSeparators($context, $charPtr, $endSlot, $id);

        $end = $context->builder->load($endSlot);
        $trimmedEmpty = self::block($context, 'basename_trimmed_empty_'.$id);
        $scanBlock = self::block($context, 'basename_scan_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $end, $zero),
            $trimmedEmpty,
            $scanBlock
        );

        $context->builder->positionAtEnd($trimmedEmpty);
        $trimmedEmptyStr = self::loadLiteral($context, '');
        $context->builder->branch($done);

        $context->builder->positionAtEnd($scanBlock);
        $lastSlot = $context->builder->alloca($i64, 1, 'basename_last_sep');
        $context->builder->store($minusOne, $lastSlot);
        $idxSlot = $context->builder->alloca($i64, 1, 'basename_idx');
        $context->builder->store($context->builder->sub($end, $one), $idxSlot);
        $minSepSlot = $context->builder->alloca($i64, 1, 'basename_min_sep');
        $context->builder->store($zero, $minSepSlot);
        self::scanBackwardForSeparator($context, $charPtr, $idxSlot, $lastSlot, $minSepSlot, $id);

        $last = $context->builder->load($lastSlot);
        $noSep = self::block($context, 'basename_no_sep_'.$id);
        $found = self::block($context, 'basename_found_'.$id);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $last, $zero),
            $noSep,
            $found
        );

        $context->builder->positionAtEnd($noSep);
        $whole = string_trim::jitCopySlice($context, $str, $charPtr, $zero, $end, $id);
        $wholeDoneBlock = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($found);
        $start = $context->builder->add($last, $one);
        $tailLen = $context->builder->sub($end, $start);
        $tail = string_trim::jitCopySlice($context, $str, $charPtr, $start, $tailLen, $id.'_tail');
        $tailDoneBlock = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($str->typeOf());
        $phi->addIncoming($emptyStr, $emptyInput);
        $phi->addIncoming($trimmedEmptyStr, $trimmedEmpty);
        $phi->addIncoming($whole, $wholeDoneBlock);
        $phi->addIncoming($tail, $tailDoneBlock);

        return $phi;
    }

    /**
     * Strip $suffix from the end of $str when it matches (php-src php_basename suffix).
     */
    public static function stripSuffixIfPresent(Context $context, Value $str, Value $suffix): Value
    {
        $id = (string) (++self::$blockSerial);
        [$strLen, $strPtr] = self::stringFields($context, $str);
        [$suffixLen, $suffixPtr] = self::stringFields($context, $suffix);
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');

        $done = self::block($context, 'basename_suffix_done_'.$id);
        $noStrip = self::block($context, 'basename_suffix_no_strip_'.$id);
        $strip = self::block($context, 'basename_suffix_strip_'.$id);

        $emptySuffix = $context->builder->icmp(Builder::INT_EQ, $suffixLen, $zero);
        $tooShort = $context->builder->icmp(Builder::INT_ULT, $strLen, $suffixLen);
        $skipStrip = $context->builder->or($emptySuffix, $tooShort);
        $context->builder->branchIf($skipStrip, $noStrip, $strip);

        $context->builder->positionAtEnd($strip);
        $start = $context->builder->sub($strLen, $suffixLen);
        $tailPtr = $context->builder->gep($strPtr, $start);
        $compareLen = $context->builder->zExt(
            $context->builder->trunc($suffixLen, $i32),
            $sizeT
        );
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $tailPtr,
            $suffixPtr,
            $compareLen
        );
        $matches = $context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
        $stripBlock = self::block($context, 'basename_suffix_do_strip_'.$id);
        $context->builder->branchIf($matches, $stripBlock, $noStrip);

        $context->builder->positionAtEnd($stripBlock);
        $stripped = string_trim::jitCopySlice($context, $str, $strPtr, JitStringIndex::zero($context), $start, $id);
        $stripDoneBlock = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($noStrip);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($str->typeOf());
        $phi->addIncoming($str, $noStrip);
        $phi->addIncoming($stripped, $stripDoneBlock);

        return $phi;
    }

    /**
     * @return array{0: Value, 1: Value}
     */
    private static function stringFields(Context $context, Value $str): array
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $charPtr = $context->builder->structGep($str, $map['value']);

        return [$len, $charPtr];
    }

    private static function loadLiteral(Context $context, string $literal): Value
    {
        return $context->builder->load($context->constantStringFromString($literal));
    }

    private static function rootOrDot(Context $context, Value $charPtr): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $first = $context->builder->load($charPtr);
        $firstI32 = $context->builder->zExt($first, $i32);
        $isSlash = $context->builder->icmp(
            Builder::INT_EQ,
            $firstI32,
            $i32->constInt(ord('/'), false)
        );

        return $context->builder->select(
            $isSlash,
            self::loadLiteral($context, '/'),
            self::loadLiteral($context, '.')
        );
    }

    private static function isPathSeparator(Context $context, Value $ch): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $byte = $context->builder->zExt($ch, $i32);
        $slash = $context->builder->icmp(Builder::INT_EQ, $byte, $i32->constInt(ord('/'), false));
        if ('Windows' !== \PHP_OS_FAMILY) {
            return $slash;
        }
        $back = $context->builder->icmp(Builder::INT_EQ, $byte, $i32->constInt(ord('\\'), false));

        return $context->builder->or($slash, $back);
    }

    private static function block(Context $context, string $name): \PHPLLVM\BasicBlock
    {
        return BasicBlockHelper::append($context, $name);
    }

    private static function trimTrailingSeparators(Context $context, Value $charPtr, Value $endSlot, string $id): void
    {
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);

        $done = self::block($context, 'path_trim_done_'.$id);
        $head = self::block($context, 'path_trim_head_'.$id);
        $body = self::block($context, 'path_trim_body_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $end = $context->builder->load($endSlot);
        $stop = $context->builder->icmp(Builder::INT_SLE, $end, $zero);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $at = $context->builder->gep($charPtr, $context->builder->sub($end, $one));
        $ch = $context->builder->load($at);
        $isSep = self::isPathSeparator($context, $ch);
        $newEnd = $context->builder->sub($end, $one);
        $context->builder->store(
            $context->builder->select($isSep, $newEnd, $end),
            $endSlot
        );
        $context->builder->branchIf($isSep, $head, $done);

        $context->builder->positionAtEnd($done);
    }

    private static function findSchemeSepIndex(
        Context $context,
        Value $charPtr,
        Value $end,
        Value $schemeSepSlot,
        string $id
    ): void {
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $minusOne = $i64->constInt(-1, false);
        $needle = self::loadLiteral($context, '://');
        [, $needlePtr] = self::stringFields($context, $needle);
        $match = $context->builder->call(
            $context->lookupFunction('strstr'),
            $charPtr,
            $needlePtr
        );
        $nullPtr = $match->typeOf()->constNull();
        $found = $context->builder->icmp(Builder::INT_NE, $match, $nullPtr);

        $done = self::block($context, 'dirname_scheme_done_'.$id);
        $miss = self::block($context, 'dirname_scheme_miss_'.$id);
        $hit = self::block($context, 'dirname_scheme_hit_'.$id);
        $context->builder->branchIf($found, $hit, $miss);

        $context->builder->positionAtEnd($miss);
        $context->builder->store($minusOne, $schemeSepSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($hit);
        $offset = $context->builder->ptrToInt($match, $i64);
        $base = $context->builder->ptrToInt($charPtr, $i64);
        $context->builder->store($context->builder->sub($offset, $base), $schemeSepSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function scanBackwardForSeparator(
        Context $context,
        Value $charPtr,
        Value $idxSlot,
        Value $lastSlot,
        Value $minIdxSlot,
        string $id
    ): void {
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);

        $done = self::block($context, 'path_scan_done_'.$id);
        $head = self::block($context, 'path_scan_head_'.$id);
        $body = self::block($context, 'path_scan_body_'.$id);
        $found = self::block($context, 'path_scan_found_'.$id);
        $continueScan = self::block($context, 'path_scan_continue_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $minIdx = $context->builder->load($minIdxSlot);
        $stop = $context->builder->icmp(Builder::INT_SLT, $idx, $minIdx);
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $at = $context->builder->gep($charPtr, $idx);
        $ch = $context->builder->load($at);
        $isSep = self::isPathSeparator($context, $ch);
        $context->builder->branchIf($isSep, $found, $continueScan);

        $context->builder->positionAtEnd($found);
        $context->builder->store($idx, $lastSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($continueScan);
        $context->builder->store($context->builder->sub($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
