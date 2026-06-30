<?php

declare(strict_types=1);

/**
 * Binary-safe substring search for JIT/AOT — mirrors VmString::findSubstring() (#4146).
 *
 * Uses length-aware memcmp loops, not libc strstr/strcasestr (NUL-unsafe).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

final class JitStringSearch
{
    public const NOT_FOUND = -1;

    private const HELPER = '__phpc_string_find_substr';
    private const HELPER_CI = '__phpc_string_find_substr_ci';

    private const MEMCMP_CI = '__phpc_string_memcasecmp';

    public static function ensureLinked(Context $context): void
    {
        self::ensureHelperLinked($context, self::HELPER, self::emitFindSubstr(...));
    }

    public static function ensureCiLinked(Context $context): void
    {
        self::ensureMemcasecmpCi($context);
        self::ensureHelperLinked($context, self::HELPER_CI, self::emitFindSubstrCi(...));
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function ensureHelperLinked(Context $context, string $name, callable $emit): void
    {
        try {
            $context->lookupFunction($name);

            return;
        } catch (\Throwable) {
        }

        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = $context->module->addFunction(
            $name,
            $context->context->functionType($i32, false, $i8p, $sizeT, $i8p, $sizeT, $sizeT)
        );
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        self::restoreInsertBlock($context, $restore);
    }

    private static function ensureMemcasecmpCi(Context $context): void
    {
        try {
            $context->lookupFunction(self::MEMCMP_CI);

            return;
        } catch (\Throwable) {
        }

        $probe = $context->module->getNamedFunction(self::MEMCMP_CI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::MEMCMP_CI, $probe);

            return;
        }

        $restore = self::captureInsertBlock($context);
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = $context->module->addFunction(
            self::MEMCMP_CI,
            $context->context->functionType($i32, false, $i8p, $i8p, $sizeT)
        );
        self::emitMemcasecmpCi($context, $fn);
        $context->registerFunction(self::MEMCMP_CI, $fn);
        self::restoreInsertBlock($context, $restore);
    }

    /**
     * str_contains() — true when needle occurs in haystack (empty needle → true).
     */
    public static function contains(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureLinked($context);
        [$hayPtr, $hayLen, $needlePtr, $needleLen] = self::stringParts($context, $haystack, $needle);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $isEmptyNeedle = $context->builder->icmp(Builder::INT_EQ, $needleLen, $zero);
        $found = $context->builder->call(
            $context->lookupFunction(self::HELPER),
            $hayPtr,
            $hayLen,
            $needlePtr,
            $needleLen,
            $zero
        );
        $notFound = $context->builder->icmp(
            Builder::INT_EQ,
            $found,
            $context->getTypeFromString('int32')->constInt(self::NOT_FOUND, true)
        );

        return $context->builder->select(
            $isEmptyNeedle,
            $context->constantFromBool(true),
            $context->builder->not($notFound)
        );
    }

    /**
     * str_starts_with() — prefix match at offset 0 (#4390).
     */
    public static function startsWith(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureLinked($context);
        [$hayPtr, $hayLen, $needlePtr, $needleLen] = self::stringParts($context, $haystack, $needle);
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $zero = $sizeT->constInt(0, false);
        $isEmptyNeedle = $context->builder->icmp(Builder::INT_EQ, $needleLen, $zero);
        $tooLong = $context->builder->icmp(Builder::INT_ULT, $hayLen, $needleLen);
        $found = $context->builder->call(
            $context->lookupFunction(self::HELPER),
            $hayPtr,
            $hayLen,
            $needlePtr,
            $needleLen,
            $zero
        );
        $atStart = $context->builder->icmp(
            Builder::INT_EQ,
            $found,
            $i32->constInt(0, false)
        );
        $ok = $context->builder->and($context->builder->not($tooLong), $atStart);

        return $context->builder->select(
            $isEmptyNeedle,
            $context->constantFromBool(true),
            $context->builder->select($tooLong, $context->constantFromBool(false), $ok)
        );
    }

    /**
     * str_ends_with() — suffix match at haystack tail (#4390).
     */
    public static function endsWith(Context $context, Value $haystack, Value $needle): Value
    {
        self::ensureLinked($context);
        [$hayPtr, $hayLen, $needlePtr, $needleLen] = self::stringParts($context, $haystack, $needle);
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $zero = $sizeT->constInt(0, false);
        $isEmptyNeedle = $context->builder->icmp(Builder::INT_EQ, $needleLen, $zero);
        $tooLong = $context->builder->icmp(Builder::INT_ULT, $hayLen, $needleLen);
        $from = $context->builder->sub($hayLen, $needleLen);
        $found = $context->builder->call(
            $context->lookupFunction(self::HELPER),
            $hayPtr,
            $hayLen,
            $needlePtr,
            $needleLen,
            $from
        );
        $atSuffix = $context->builder->icmp(
            Builder::INT_EQ,
            $found,
            $context->builder->trunc($from, $i32)
        );
        $ok = $context->builder->and($context->builder->not($tooLong), $atSuffix);

        return $context->builder->select(
            $isEmptyNeedle,
            $context->constantFromBool(true),
            $context->builder->select($tooLong, $context->constantFromBool(false), $ok)
        );
    }

    /**
     * Raw byte offset as i32, or {@see self::NOT_FOUND} (-1) when absent.
     */
    public static function findOffsetI32(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset = null,
        bool $caseInsensitive = false
    ): Value {
        if ($caseInsensitive) {
            self::ensureCiLinked($context);
        } else {
            self::ensureLinked($context);
        }
        [$hayPtr, $hayLen, $needlePtr, $needleLen] = self::stringParts($context, $haystack, $needle);
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $zeroSize = $sizeT->constInt(0, false);
        $from = null === $offset
            ? $zeroSize
            : $context->builder->zExt(
                $context->builder->trunc($offset, $i32),
                $sizeT
            );
        $helper = $caseInsensitive ? self::HELPER_CI : self::HELPER;

        return $context->builder->call(
            $context->lookupFunction($helper),
            $hayPtr,
            $hayLen,
            $needlePtr,
            $needleLen,
            $from
        );
    }

    /**
     * strpos()/stripos() — byte offset or NOT_FOUND (0 for JIT strpos sentinel).
     */
    public static function find(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $offset = null,
        bool $caseInsensitive = false
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $found = self::findOffsetI32($context, $haystack, $needle, $offset, $caseInsensitive);
        $notFound = $context->builder->icmp(
            Builder::INT_EQ,
            $found,
            $i32->constInt(self::NOT_FOUND, true)
        );
        $pos = $context->builder->zExt($found, $i64);
        $sentinel = $i64->constInt(JitStrpos::NOT_FOUND, false);

        return $context->builder->select($notFound, $sentinel, $pos);
    }

    /** @return array{0: Value, 1: Value, 2: Value, 3: Value} */
    private static function stringParts(Context $context, Value $haystack, Value $needle): array
    {
        $map = $context->structFieldMap['__string__'];

        return [
            $context->builder->structGep($haystack, $map['value']),
            $context->builder->load($context->builder->structGep($haystack, $map['length'])),
            $context->builder->structGep($needle, $map['value']),
            $context->builder->load($context->builder->structGep($needle, $map['length'])),
        ];
    }

    private static function emitFindSubstr(Context $context, LlvmFunction $fn): void
    {
        self::emitFindSubstrLoop($context, $fn, 'memcmp');
    }

    private static function emitFindSubstrCi(Context $context, LlvmFunction $fn): void
    {
        self::emitFindSubstrLoop($context, $fn, self::MEMCMP_CI);
    }

    private static function emitFindSubstrLoop(Context $context, LlvmFunction $fn, string $cmpFn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $minusOne = $i32->constInt(self::NOT_FOUND, true);

        $hay = $fn->getParam(0);
        $hlen = $fn->getParam(1);
        $needle = $fn->getParam(2);
        $nlen = $fn->getParam(3);
        $from = $fn->getParam(4);

        $invalid = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $nlen, $sizeT->constInt(0, false)),
            $context->builder->icmp(
                Builder::INT_UGT,
                $context->builder->add($from, $nlen),
                $hlen
            )
        );
        $retNeg = $fn->appendBasicBlock('ret_neg');
        $loopHead = $fn->appendBasicBlock('loop_head');
        $context->builder->branchIf($invalid, $retNeg, $loopHead);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->positionAtEnd($loopHead);
        $context->builder->store($from, $iSlot);
        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $canCheck = $context->builder->icmp(Builder::INT_ULE, $context->builder->add($i, $nlen), $hlen);
        $context->builder->branchIf($canCheck, $body, $done);

        $context->builder->positionAtEnd($body);
        $cmp = $context->builder->call(
            $context->lookupFunction($cmpFn),
            $context->bytePtr($context->builder->inBoundsGEP($hay, $i)),
            $context->bytePtr($needle),
            $nlen
        );
        $matched = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $found = $fn->appendBasicBlock('found');
        $next = $fn->appendBasicBlock('next');
        $context->builder->branchIf($matched, $found, $next);

        $context->builder->positionAtEnd($found);
        $context->builder->returnValue($context->builder->trunc($i, $i32));

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($retNeg);
        $context->builder->returnValue($minusOne);
    }

    /** ASCII case-insensitive memcmp — mirrors VmString::compareBytesCaseInsensitive(). */
    private static function emitMemcasecmpCi(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $left = $fn->getParam(0);
        $right = $fn->getParam(1);
        $len = $fn->getParam(2);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $retZero = $fn->appendBasicBlock('ret_zero');
        $loopHead = $fn->appendBasicBlock('loop_head');
        $context->builder->branchIf($isEmpty, $retZero, $loopHead);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->positionAtEnd($loopHead);
        $context->builder->store($zero, $iSlot);
        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $hasMore = $context->builder->icmp(Builder::INT_ULT, $i, $len);
        $context->builder->branchIf($hasMore, $body, $done);

        $context->builder->positionAtEnd($body);
        $leftByte = $context->builder->load($context->builder->inBoundsGEP($left, $i));
        $rightByte = $context->builder->load($context->builder->inBoundsGEP($right, $i));
        $leftLower = self::asciiLowerByte($context, $leftByte);
        $rightLower = self::asciiLowerByte($context, $rightByte);
        $diff = $context->builder->icmp(Builder::INT_NE, $leftLower, $rightLower);
        $mismatch = $fn->appendBasicBlock('mismatch');
        $next = $fn->appendBasicBlock('next');
        $context->builder->branchIf($diff, $mismatch, $next);

        $context->builder->positionAtEnd($mismatch);
        $context->builder->returnValue($i32->constInt(1, false));

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->branch($retZero);

        $context->builder->positionAtEnd($retZero);
        $context->builder->returnValue($i32->constInt(0, false));
    }

    private static function asciiLowerByte(Context $context, Value $byte): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $ord = $context->builder->zExt($byte, $i32);
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i32->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i32->constInt(90, false))
        );

        return $context->builder->trunc(
            $context->builder->select(
                $isUpper,
                $context->builder->add($ord, $i32->constInt(32, false)),
                $ord
            ),
            $context->getTypeFromString('int8')
        );
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
