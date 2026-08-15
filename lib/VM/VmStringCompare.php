<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\VmValueCompare;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\OpCode;
use function PHPCompiler\opcode_type_name;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SSOT for JIT native __string__ compare lowering (Zend zend_operators.c, #9972).
 *
 * php-src: Zend/zend_operators.c — compare_function string branch
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitStringCompare}
 */

final class VmStringCompare
{
    public static function binaryOp(
        Context $context,
        OpCode $opcode,
        Value $leftStr,
        Value $rightStr
    ): Value {
        switch ($opcode->type) {
            case OpCode::TYPE_IDENTICAL:
                return self::identical($context, $leftStr, $rightStr);
            case OpCode::TYPE_EQUAL:
                return VmValueCompare::looseEqualStringToString($context, $leftStr, $rightStr);
            case OpCode::TYPE_NOT_IDENTICAL:
            case OpCode::TYPE_NOT_EQUAL:
                $same = self::identical($context, $leftStr, $rightStr);
                $i1 = $context->getTypeFromString('int1');

                return $context->builder->xor($same, $i1->constInt(1, false));
            case OpCode::TYPE_SMALLER:
            case OpCode::TYPE_GREATER:
            case OpCode::TYPE_SMALLER_OR_EQUAL:
            case OpCode::TYPE_GREATER_OR_EQUAL:
                return self::orderedCompare($context, $opcode->type, $leftStr, $rightStr);
            case OpCode::TYPE_SPACESHIP:
                return self::smartStrcmp($context, $leftStr, $rightStr);
            default:
                throw new \LogicException(
                    'String/string comparison opcode not implemented for JIT: '.opcode_type_name($opcode->type)
                );
        }
    }

    private static function orderedCompare(
        Context $context,
        int $opcodeType,
        Value $leftStr,
        Value $rightStr
    ): Value {
        // Zend zendi_smart_strcmp — numeric strings as numbers (#22848).
        $cmp = self::smartStrcmp($context, $leftStr, $rightStr);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        return match ($opcodeType) {
            OpCode::TYPE_SMALLER => $context->builder->icmp(Builder::INT_SLT, $cmp, $zero),
            OpCode::TYPE_GREATER => $context->builder->icmp(Builder::INT_SGT, $cmp, $zero),
            OpCode::TYPE_SMALLER_OR_EQUAL => $context->builder->icmp(Builder::INT_SLE, $cmp, $zero),
            OpCode::TYPE_GREATER_OR_EQUAL => $context->builder->icmp(Builder::INT_SGE, $cmp, $zero),
            default => throw new \LogicException(
                'String/string ordering opcode not implemented for JIT: '.opcode_type_name($opcodeType)
            ),
        };
    }

    /**
     * Zend zendi_smart_strcmp on native {@see __string__} (#22848).
     *
     * @return Value i64 -1 / 0 / 1
     */
    public static function smartStrcmp(Context $context, Value $leftStr, Value $rightStr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $lex = self::strcmp($context, $leftStr, $rightStr);
        $bothNumeric = $context->builder->and(
            VmValueCompare::stringIsNumeric($context, $leftStr),
            VmValueCompare::stringIsNumeric($context, $rightStr)
        );
        $leftDouble = VmValueCompare::stringToDouble($context, $leftStr);
        $rightDouble = VmValueCompare::stringToDouble($context, $rightStr);
        $lt = $context->builder->fcmp(Builder::REAL_OLT, $leftDouble, $rightDouble);
        $gt = $context->builder->fcmp(Builder::REAL_OGT, $leftDouble, $rightDouble);
        $negOne = $i64->constInt(-1, true);
        $one = $i64->constInt(1, true);
        $zero = $i64->constInt(0, false);
        $numericCmp = $context->builder->select(
            $gt,
            $one,
            $context->builder->select($lt, $negOne, $zero)
        );
        $lexNorm = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $lex, $zero),
            $negOne,
            $context->builder->select(
                $context->builder->icmp(Builder::INT_SGT, $lex, $zero),
                $one,
                $zero
            )
        );

        return $context->builder->select($bothNumeric, $numericCmp, $lexNorm);
    }

    /** True when $haystack starts with the same bytes as $prefix (#24161). */
    public static function prefixIdentical(Context $context, Value $haystack, Value $prefix): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $prefixLen = $context->builder->load(
            $context->builder->structGep($prefix, $map['length'])
        );
        $lenOk = $context->builder->icmp(Builder::INT_SGE, $hayLen, $prefixLen);
        $lenOkBb = BasicBlockHelper::append($context, 'jit_prefix_len_ok');
        $lenBadBb = BasicBlockHelper::append($context, 'jit_prefix_len_bad');
        $mergeBb = BasicBlockHelper::append($context, 'jit_prefix_done');
        $context->builder->branchIf($lenOk, $lenOkBb, $lenBadBb);
        $context->builder->positionAtEnd($lenBadBb);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($lenOkBb);
        $sizeT = $context->getTypeFromString('size_t');
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $context->builder->structGep($haystack, $map['value']),
            $context->builder->structGep($prefix, $map['value']),
            $context->builder->zExt($prefixLen, $sizeT)
        );
        $prefixEq = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $lenBadBb);
        $phi->addIncoming($prefixEq, $lenOkBb);

        return $phi;
    }

    /** True when $haystack ends with the same bytes as $suffix (inventory argv absolute paths — #3046). */
    public static function suffixIdentical(Context $context, Value $haystack, Value $suffix): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $suffixLen = $context->builder->load(
            $context->builder->structGep($suffix, $map['length'])
        );
        $lenOk = $context->builder->icmp(Builder::INT_SGE, $hayLen, $suffixLen);
        $lenOkBb = BasicBlockHelper::append($context, 'jit_suffix_len_ok');
        $lenBadBb = BasicBlockHelper::append($context, 'jit_suffix_len_bad');
        $mergeBb = BasicBlockHelper::append($context, 'jit_suffix_done');
        $context->builder->branchIf($lenOk, $lenOkBb, $lenBadBb);
        $context->builder->positionAtEnd($lenBadBb);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($lenOkBb);
        $offset = $context->builder->sub($hayLen, $suffixLen);
        $i8p = $context->getTypeFromString('int8*');
        $hayChars = $context->builder->structGep($haystack, $map['value']);
        $hayTail = $context->builder->gep($hayChars, $offset);
        $suffixChars = $context->builder->structGep($suffix, $map['value']);
        $sizeT = $context->getTypeFromString('size_t');
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $hayTail,
            $suffixChars,
            $context->builder->zExt($suffixLen, $sizeT)
        );
        $suffixEq = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $lenBadBb);
        $phi->addIncoming($suffixEq, $lenOkBb);

        return $phi;
    }

    /**
     * Zend strcmp() ordering on native {@see __string__} operands (ext/standard/string.c).
     *
     * Length-tracked buffers are not guaranteed null-terminated; C strcmp on raw
     * char* can read past the buffer (bootstrap concat VALUE-box globals — #1492).
     */
    public static function strcmp(Context $context, Value $leftStr, Value $rightStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $nullStr = $context->getTypeFromString('__string__*')->constNull();
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $leftNull = $context->builder->icmp(Builder::INT_EQ, $leftStr, $nullStr);
        $rightNull = $context->builder->icmp(Builder::INT_EQ, $rightStr, $nullStr);
        $leftStr = $context->builder->select($leftNull, $emptyStr, $leftStr);
        $rightStr = $context->builder->select($rightNull, $emptyStr, $rightStr);
        $leftLen = $context->builder->load(
            $context->builder->structGep($leftStr, $map['length'])
        );
        $rightLen = $context->builder->load(
            $context->builder->structGep($rightStr, $map['length'])
        );
        $leftLtRight = $context->builder->icmp(Builder::INT_SLT, $leftLen, $rightLen);
        $minLen = $context->builder->select($leftLtRight, $leftLen, $rightLen);
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $context->builder->structGep($leftStr, $map['value']),
            $context->builder->structGep($rightStr, $map['value']),
            $context->builder->zExt($minLen, $sizeT)
        );
        $cmpNeZero = $context->builder->icmp(
            Builder::INT_NE,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $prefixResult = $context->builder->sExt($cmp, $i64);
        $lenDiff = $context->builder->sub($leftLen, $rightLen);

        return $context->builder->select($cmpNeZero, $prefixResult, $lenDiff);
    }

    public static function identical(Context $context, Value $leftStr, Value $rightStr): Value
    {
        // NestedJIT / entryAlloca can leave insert cleared mid-Runtime::parse; without an
        // open BB, branchIf to jit_strcmp_* is parentless and sealFunction writes unreachable
        // onto the prior block (#26756).
        BasicBlockHelper::ensureOpenInsertBlock($context, 'jit_strcmp_identical_entry');
        $map = $context->structFieldMap['__string__'];
        $leftLen = $context->builder->load(
            $context->builder->structGep($leftStr, $map['length'])
        );
        $rightLen = $context->builder->load(
            $context->builder->structGep($rightStr, $map['length'])
        );
        $lenEq = $context->builder->icmp(Builder::INT_EQ, $leftLen, $rightLen);
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);

        // __string__ is length-tracked and not guaranteed to be null-terminated; use memcmp
        // guarded by length equality (strcmp can read past the buffer and/or mismatch).
        $lenOk = BasicBlockHelper::append($context, 'jit_strcmp_len_ok');
        $lenBad = BasicBlockHelper::append($context, 'jit_strcmp_len_bad');
        $merge = BasicBlockHelper::append($context, 'jit_strcmp_done');
        $context->builder->branchIf($lenEq, $lenOk, $lenBad);

        $context->builder->positionAtEnd($lenBad);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($lenOk);
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->zExt($leftLen, $sizeT);
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $context->builder->structGep($leftStr, $map['value']),
            $context->builder->structGep($rightStr, $map['value']),
            $len
        );
        $strEq = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $lenBad);
        $phi->addIncoming($strEq, $lenOk);

        return $phi;
    }

    /**
     * True when $haystack contains $needle as a byte subsequence (#26796 / #24161 class).
     *
     * Empty needle → true (php-src str_contains). Sliding memcmp window — NestedJIT of
     * StrContainsJitHelper::containsArgv returns always-true under AOT (#15704 bool path).
     */
    public static function containsIdentical(Context $context, Value $haystack, Value $needle): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i1 = $context->getTypeFromString('int1');
        $i64 = $context->getTypeFromString('int64');
        $trueVal = $i1->constInt(1, false);
        $falseVal = $i1->constInt(0, false);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $needleLen = $context->builder->load(
            $context->builder->structGep($needle, $map['length'])
        );
        $needleEmpty = $context->builder->icmp(Builder::INT_EQ, $needleLen, $zero);
        $emptyBb = BasicBlockHelper::append($context, 'jit_contains_empty_needle');
        $checkLenBb = BasicBlockHelper::append($context, 'jit_contains_check_len');
        $loopHeader = BasicBlockHelper::append($context, 'jit_contains_loop_header');
        $loopBody = BasicBlockHelper::append($context, 'jit_contains_loop_body');
        $foundBb = BasicBlockHelper::append($context, 'jit_contains_found');
        $advanceBb = BasicBlockHelper::append($context, 'jit_contains_advance');
        $missBb = BasicBlockHelper::append($context, 'jit_contains_miss');
        $mergeBb = BasicBlockHelper::append($context, 'jit_contains_done');
        $context->builder->branchIf($needleEmpty, $emptyBb, $checkLenBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($checkLenBb);
        $lenOk = $context->builder->icmp(Builder::INT_SGE, $hayLen, $needleLen);
        $context->builder->branchIf($lenOk, $loopHeader, $missBb);

        $context->builder->positionAtEnd($loopHeader);
        $idx = $context->builder->phi($i64);
        $idx->addIncoming($zero, $checkLenBb);
        $limit = $context->builder->sub($hayLen, $needleLen);
        $inRange = $context->builder->icmp(Builder::INT_SLE, $idx, $limit);
        $context->builder->branchIf($inRange, $loopBody, $missBb);

        $context->builder->positionAtEnd($loopBody);
        $sizeT = $context->getTypeFromString('size_t');
        $hayChars = $context->builder->structGep($haystack, $map['value']);
        $window = $context->builder->gep($hayChars, $idx);
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $window,
            $context->builder->structGep($needle, $map['value']),
            $context->builder->zExt($needleLen, $sizeT)
        );
        $eq = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $context->builder->branchIf($eq, $foundBb, $advanceBb);

        $context->builder->positionAtEnd($advanceBb);
        $next = $context->builder->add($idx, $one);
        $idx->addIncoming($next, $advanceBb);
        $context->builder->branch($loopHeader);

        $context->builder->positionAtEnd($foundBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($trueVal, $emptyBb);
        $phi->addIncoming($trueVal, $foundBb);
        $phi->addIncoming($falseVal, $missBb);

        return $phi;
    }

    /**
     * Byte offset of $needle in $haystack from $offset, or -1 when absent (#27184).
     *
     * Sliding memcmp — NestedJIT of StrposJitHelper miss returns corrupt/0 under thin AOT.
     * Empty needle → $offset (php-src strpos). Case-insensitive uses ASCII tolower in IR.
     */
    public static function findOffset(
        Context $context,
        Value $haystack,
        Value $needle,
        Value $offset,
        bool $caseInsensitive = false
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $notFound = $i64->constInt(-1, true);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $needleLen = $context->builder->load(
            $context->builder->structGep($needle, $map['length'])
        );
        $off = $context->builder->truncOrBitCast($offset, $i64);

        $needleEmpty = $context->builder->icmp(Builder::INT_EQ, $needleLen, $zero);
        $emptyBb = BasicBlockHelper::append($context, 'jit_find_empty_needle');
        $checkOffBb = BasicBlockHelper::append($context, 'jit_find_check_off');
        $checkLenBb = BasicBlockHelper::append($context, 'jit_find_check_len');
        $loopHeader = BasicBlockHelper::append($context, 'jit_find_loop_header');
        $loopBody = BasicBlockHelper::append($context, 'jit_find_loop_body');
        $foundBb = BasicBlockHelper::append($context, 'jit_find_found');
        $advanceBb = BasicBlockHelper::append($context, 'jit_find_advance');
        $missBb = BasicBlockHelper::append($context, 'jit_find_miss');
        $mergeBb = BasicBlockHelper::append($context, 'jit_find_done');
        $context->builder->branchIf($needleEmpty, $emptyBb, $checkOffBb);

        $context->builder->positionAtEnd($emptyBb);
        // Empty needle: return offset when 0 <= offset <= hayLen, else -1.
        $offGe0 = $context->builder->icmp(Builder::INT_SGE, $off, $zero);
        $offLeLen = $context->builder->icmp(Builder::INT_SLE, $off, $hayLen);
        $emptyOk = $context->builder->and($offGe0, $offLeLen);
        $emptyResult = $context->builder->select($emptyOk, $off, $notFound);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($checkOffBb);
        $offOk = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $off, $zero),
            $context->builder->icmp(Builder::INT_SLE, $off, $hayLen)
        );
        $context->builder->branchIf($offOk, $checkLenBb, $missBb);

        $context->builder->positionAtEnd($checkLenBb);
        $remain = $context->builder->sub($hayLen, $off);
        $lenOk = $context->builder->icmp(Builder::INT_SGE, $remain, $needleLen);
        $context->builder->branchIf($lenOk, $loopHeader, $missBb);

        $context->builder->positionAtEnd($loopHeader);
        $idx = $context->builder->phi($i64);
        $idx->addIncoming($off, $checkLenBb);
        $limit = $context->builder->sub($hayLen, $needleLen);
        $inRange = $context->builder->icmp(Builder::INT_SLE, $idx, $limit);
        $context->builder->branchIf($inRange, $loopBody, $missBb);

        $context->builder->positionAtEnd($loopBody);
        $hayChars = $context->builder->structGep($haystack, $map['value']);
        $window = $context->builder->gep($hayChars, $idx);
        $needleChars = $context->builder->structGep($needle, $map['value']);
        if ($caseInsensitive) {
            $eq = self::emitAsciiCiEqual($context, $window, $needleChars, $needleLen);
        } else {
            $cmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $window,
                $needleChars,
                $context->builder->zExt($needleLen, $sizeT)
            );
            $eq = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $cmp->typeOf()->constInt(0, false)
            );
        }
        $context->builder->branchIf($eq, $foundBb, $advanceBb);

        $context->builder->positionAtEnd($advanceBb);
        $next = $context->builder->add($idx, $one);
        $idx->addIncoming($next, $advanceBb);
        $context->builder->branch($loopHeader);

        $context->builder->positionAtEnd($foundBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($emptyResult, $emptyBb);
        $phi->addIncoming($idx, $foundBb);
        $phi->addIncoming($notFound, $missBb);

        return $phi;
    }

    /**
     * Last byte offset of $needle in $haystack (Zend strrpos offset window), or -1 (#27184).
     */
    public static function findROffset(
        Context $context,
        Value $haystack,
        Value $needle,
        Value $offset,
        bool $caseInsensitive = false
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $notFound = $i64->constInt(-1, true);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $hayLen = $context->builder->load(
            $context->builder->structGep($haystack, $map['length'])
        );
        $needleLen = $context->builder->load(
            $context->builder->structGep($needle, $map['length'])
        );
        $off = $context->builder->truncOrBitCast($offset, $i64);

        // Positive offset: minStart = offset; negative: maxStart = hayLen+offset.
        $offNeg = $context->builder->icmp(Builder::INT_SLT, $off, $zero);
        $suffixEnd = $context->builder->add($hayLen, $off);
        $minStart = $context->builder->select($offNeg, $zero, $off);
        $maxStart = $context->builder->select($offNeg, $suffixEnd, $hayLen);

        $negBad = $context->builder->and(
            $offNeg,
            $context->builder->icmp(Builder::INT_SLT, $suffixEnd, $zero)
        );
        $badBb = BasicBlockHelper::append($context, 'jit_rfind_bad');
        $emptyCheckBb = BasicBlockHelper::append($context, 'jit_rfind_empty_check');
        $emptyBb = BasicBlockHelper::append($context, 'jit_rfind_empty');
        $lenCheckBb = BasicBlockHelper::append($context, 'jit_rfind_len');
        $loopHeader = BasicBlockHelper::append($context, 'jit_rfind_loop_header');
        $loopBody = BasicBlockHelper::append($context, 'jit_rfind_loop_body');
        $foundBb = BasicBlockHelper::append($context, 'jit_rfind_found');
        $retreatBb = BasicBlockHelper::append($context, 'jit_rfind_retreat');
        $missBb = BasicBlockHelper::append($context, 'jit_rfind_miss');
        $mergeBb = BasicBlockHelper::append($context, 'jit_rfind_done');
        $context->builder->branchIf($negBad, $badBb, $emptyCheckBb);

        $context->builder->positionAtEnd($badBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($emptyCheckBb);
        $needleEmpty = $context->builder->icmp(Builder::INT_EQ, $needleLen, $zero);
        $context->builder->branchIf($needleEmpty, $emptyBb, $lenCheckBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyResult = $maxStart;
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($lenCheckBb);
        $tooLong = $context->builder->icmp(Builder::INT_SGT, $needleLen, $hayLen);
        $start0 = $context->builder->sub($maxStart, $needleLen);
        $maxIdx = $context->builder->sub($hayLen, $needleLen);
        $startClamped = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $start0, $maxIdx),
            $maxIdx,
            $start0
        );
        $context->builder->branchIf($tooLong, $missBb, $loopHeader);

        $context->builder->positionAtEnd($loopHeader);
        $idx = $context->builder->phi($i64);
        $idx->addIncoming($startClamped, $lenCheckBb);
        $inRange = $context->builder->icmp(Builder::INT_SGE, $idx, $minStart);
        $context->builder->branchIf($inRange, $loopBody, $missBb);

        $context->builder->positionAtEnd($loopBody);
        $hayChars = $context->builder->structGep($haystack, $map['value']);
        $window = $context->builder->gep($hayChars, $idx);
        $needleChars = $context->builder->structGep($needle, $map['value']);
        if ($caseInsensitive) {
            $eq = self::emitAsciiCiEqual($context, $window, $needleChars, $needleLen);
        } else {
            $cmp = $context->builder->call(
                $context->lookupFunction('memcmp'),
                $window,
                $needleChars,
                $context->builder->zExt($needleLen, $sizeT)
            );
            $eq = $context->builder->icmp(
                Builder::INT_EQ,
                $cmp,
                $cmp->typeOf()->constInt(0, false)
            );
        }
        $context->builder->branchIf($eq, $foundBb, $retreatBb);

        $context->builder->positionAtEnd($retreatBb);
        $prev = $context->builder->sub($idx, $one);
        $idx->addIncoming($prev, $retreatBb);
        $context->builder->branch($loopHeader);

        $context->builder->positionAtEnd($foundBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($notFound, $badBb);
        $phi->addIncoming($emptyResult, $emptyBb);
        $phi->addIncoming($idx, $foundBb);
        $phi->addIncoming($notFound, $missBb);

        return $phi;
    }

    /** ASCII case-insensitive equality of $len bytes at $a / $b. */
    private static function emitAsciiCiEqual(
        Context $context,
        Value $a,
        Value $b,
        Value $len
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $trueVal = $i1->constInt(1, false);
        $falseVal = $i1->constInt(0, false);

        $preHeader = $context->builder->getInsertBlock();
        $header = BasicBlockHelper::append($context, 'jit_ci_eq_header');
        $body = BasicBlockHelper::append($context, 'jit_ci_eq_body');
        $nextBb = BasicBlockHelper::append($context, 'jit_ci_eq_next');
        $okBb = BasicBlockHelper::append($context, 'jit_ci_eq_ok');
        $failBb = BasicBlockHelper::append($context, 'jit_ci_eq_fail');
        $doneBb = BasicBlockHelper::append($context, 'jit_ci_eq_done');
        $context->builder->branch($header);

        $context->builder->positionAtEnd($header);
        $j = $context->builder->phi($i64);
        $j->addIncoming($zero, $preHeader);
        $inRange = $context->builder->icmp(Builder::INT_SLT, $j, $len);
        $context->builder->branchIf($inRange, $body, $okBb);

        $context->builder->positionAtEnd($body);
        $ca = $context->builder->load($context->builder->gep($a, $j));
        $cb = $context->builder->load($context->builder->gep($b, $j));
        $la = self::emitAsciiToLowerI8($context, $ca);
        $lb = self::emitAsciiToLowerI8($context, $cb);
        $same = $context->builder->icmp(Builder::INT_EQ, $la, $lb);
        $context->builder->branchIf($same, $nextBb, $failBb);

        $context->builder->positionAtEnd($nextBb);
        $jn = $context->builder->add($j, $one);
        $j->addIncoming($jn, $nextBb);
        $context->builder->branch($header);

        $context->builder->positionAtEnd($okBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($trueVal, $okBb);
        $phi->addIncoming($falseVal, $failBb);

        return $phi;
    }

    private static function emitAsciiToLowerI8(Context $context, Value $ch): Value
    {
        $i8 = $ch->typeOf();
        $i32 = $context->getTypeFromString('int32');
        $ext = $context->builder->zExt($ch, $i32);
        $geA = $context->builder->icmp(
            Builder::INT_SGE,
            $ext,
            $i32->constInt(65, false)
        );
        $leZ = $context->builder->icmp(
            Builder::INT_SLE,
            $ext,
            $i32->constInt(90, false)
        );
        $isUpper = $context->builder->and($geA, $leZ);
        $lowered = $context->builder->add($ext, $i32->constInt(32, false));
        $chosen = $context->builder->select($isUpper, $lowered, $ext);

        return $context->builder->trunc($chosen, $i8);
    }

    /**
     * Strict equality between a boxed {@see __value__} and a native {@see __string__}.
     */
    public static function identicalValueToString(
        Context $context,
        JitVariable $boxed,
        Value $nativeStr
    ): Value {
        if (!JitValueBox::isValueOperand($boxed)) {
            throw new \LogicException('Expected boxed __value__ operand');
        }
        // Resume open BB before readString/identical — cleared/sealed insert leaves parentless
        // @__value__readString and orphan jit_strcmp_* blocks under M5 argv (#26756).
        BasicBlockHelper::ensureOpenInsertBlock($context, 'jit_strcmp_value_to_string');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $boxedStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $nullStr = $context->getTypeFromString('__string__*')->constNull();
        $hasString = $context->builder->icmp(
            Builder::INT_NE,
            $boxedStr,
            $nullStr
        );
        // Branch — do not select()+eager identical(): LLVM evaluates both select arms, so a
        // null boxedStr would structGep in identical() (#31101 MiniWebApp / value-box ===).
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $cmpBb = BasicBlockHelper::append($context, 'jit_strcmp_value_has_string');
        $noBb = BasicBlockHelper::append($context, 'jit_strcmp_value_not_string');
        $mergeBb = BasicBlockHelper::append($context, 'jit_strcmp_value_to_string_done');
        $context->builder->branchIf($hasString, $cmpBb, $noBb);

        $context->builder->positionAtEnd($noBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($cmpBb);
        $same = self::identical($context, $boxedStr, $nativeStr);
        $cmpDone = BasicBlockHelper::tryGetInsertBlock($context) ?? $cmpBb;
        $context->builder->positionAtEnd($cmpDone);
        if (null === $cmpDone->getTerminator()) {
            $context->builder->branch($mergeBb);
        }

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $noBb);
        $phi->addIncoming($same, $cmpDone);

        return $phi;
    }

    public static function identicalStringToValue(
        Context $context,
        Value $nativeStr,
        JitVariable $boxed
    ): Value {
        return self::identicalValueToString($context, $boxed, $nativeStr);
    }
}
