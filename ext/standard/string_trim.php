<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTrimMask;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * trim() for strings (default whitespace or optional $characters mask; php-src string.c).
 */
final class string_trim extends Internal
{
    private static int $sliceBlockSerial = 0;

    private static int $trimBlockSerial = 0;

    public function __construct()
    {
        parent::__construct('trim');
    }

    public function execute(Frame $frame): void
    {
        // php-src string.stub.php — arity ≤2; no $mode (#28230 / #28202).
        $this->requireArgCountRange($frame, 'trim', 1, 2);
        $string = self::vmStringArg($frame, 0, 'string');
        if ('' === $string) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->string('');

            return;
        }
        [$mask, $mode] = VmString::resolveTrimMaskAndMode(
            \array_slice($frame->calledArgs, 1),
            'trim',
            VmString::TRIM_SIDE_BOTH
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::trimInt($string, $mask, $mode));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireArgCountRangeJit($context, $args, 'trim', 1, 2)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        if (
            !$context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            $emptyPtr = JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[0],
                'trim',
                0,
                'string'
            );

            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $emptyPtr
            );
        }
        $literal = $args[0]->compileTimeString ?? null;
        $optional = \array_slice($args, 1);
        $optCount = \count($optional);
        $maskLiteral = 1 === $optCount ? ($optional[0]->compileTimeString ?? null) : null;
        if (null !== $literal && (0 === $optCount || null !== $maskLiteral)) {
            $mask = null !== $maskLiteral ? $maskLiteral : VmString::TRIM_DEFAULT;

            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $context->builder->load(
                    $context->constantStringFromString(
                        VmString::trimInt($literal, $mask, VmString::TRIM_SIDE_BOTH)
                    )
                )
            );
        }
        $mode = VmString::TRIM_SIDE_BOTH;
        $maskStr = null;
        if (1 === $optCount) {
            StringTrimMask::ensureLinked($context);
            $maskStr = JitStringBuiltinArg::lower($context, $optional[0], 'trim', 1, 'characters');
        }
        $str = self::jitStringArg($context, $args[0], 0, 'string');
        $early = self::jitReturnIfCoercedEmptyTrimInput($context, $args[0], $str);
        if (null !== $early) {
            return $early;
        }
        $str = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        if (null !== $maskStr) {
            $maskStr = $context->builder->call($context->lookupFunction('__string__separate'), $maskStr);
        }
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $charPtr = $context->builder->structGep($str, $map['value']);

        $startSlot = $context->builder->alloca($i64, 1, 'trim_start');
        $endSlot = $context->builder->alloca($i64, 1, 'trim_end');
        $context->builder->store($zero, $startSlot);
        $context->builder->store($len, $endSlot);

        if ($mode & VmString::TRIM_SIDE_LEFT) {
            self::advanceWhileTrimByte($context, $charPtr, $len, $startSlot, true, 'trim', $maskStr);
        }
        if ($mode & VmString::TRIM_SIDE_RIGHT) {
            self::advanceWhileTrimByte($context, $charPtr, $len, $endSlot, false, 'trim', $maskStr);
        }

        $start = $context->builder->load($startSlot);
        $end = $context->builder->load($endSlot);
        $newLen = $context->builder->sub($end, $start);

        return self::jitCopySlice($context, $str, $charPtr, $start, $newLen, 'trim');
    }

    public static function jitCopySlice(
        Context $context,
        Value $str,
        Value $charPtr,
        Value $start,
        Value $sliceLen,
        string $blockId = ''
    ): Value {
        $suffix = ('' === $blockId ? '' : '_'.$blockId).'_'.(string) (++self::$sliceBlockSerial);
        $zero = JitStringIndex::zero($context);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $sliceLen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'slice_empty'.$suffix);
        $copyBlock = BasicBlockHelper::append($context, 'slice_copy'.$suffix);
        $doneBlock = BasicBlockHelper::append($context, 'slice_done'.$suffix);
        $context->builder->branchIf($isEmpty, $emptyBlock, $copyBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($copyBlock);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $sliceLen);
        $destMap = $context->structFieldMap['__string__'];
        $context->builder->store(
            $sliceLen,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $srcAt = $context->builder->gep($charPtr, $start);
        $destAt = $context->builder->structGep($dest, $destMap['value']);
        $context->intrinsic->memcpy($destAt, $srcAt, $sliceLen, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $copyBlock);

        BasicBlockHelper::branchToFreshContinue($context, 'slice_continue'.$suffix);

        return $result;
    }

    public static function advanceWhileTrimByte(
        Context $context,
        Value $charPtr,
        Value $len,
        Value $indexSlot,
        bool $fromStart,
        string $blockId = '',
        ?Value $maskStr = null
    ): void {
        $suffix = ('' === $blockId ? '' : '_'.$blockId).'_'.(string) (++self::$trimBlockSerial);
        $i64 = JitStringIndex::i64($context);
        $zero = JitStringIndex::zero($context);
        $one = $i64->constInt(1, false);

        $done = BasicBlockHelper::append($context, ($fromStart ? 'trim_start_done' : 'trim_end_done').$suffix);
        $loopHead = BasicBlockHelper::append($context, ($fromStart ? 'trim_start_head' : 'trim_end_head').$suffix);
        $loopBody = BasicBlockHelper::append($context, ($fromStart ? 'trim_start_body' : 'trim_end_body').$suffix);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($indexSlot);
        if ($fromStart) {
            $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        } else {
            $stop = $context->builder->icmp(Builder::INT_SLE, $idx, $zero);
        }
        $context->builder->branchIf($stop, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $at = $fromStart
            ? $context->builder->gep($charPtr, $idx)
            : $context->builder->gep($charPtr, $context->builder->sub($idx, $one));
        $ch = $context->builder->load($at);
        $chI32 = $context->builder->zExt($ch, $context->getTypeFromString('int32'));
        $isTrim = self::jitIsMaskByte($context, $chI32, $maskStr);
        $continueLoop = $fromStart
            ? $context->builder->addNoSignedWrap($idx, $one)
            : $context->builder->sub($idx, $one);
        $context->builder->store(
            $context->builder->select($isTrim, $continueLoop, $idx),
            $indexSlot
        );
        $context->builder->branchIf($isTrim, $loopHead, $done);

        $context->builder->positionAtEnd($done);
    }

    private static function jitIsMaskByte(Context $context, Value $ch, ?Value $maskStr): Value
    {
        if (null === $maskStr) {
            $i32 = $context->getTypeFromString('int32');
            $checks = [];
            foreach ([0x20, 0x09, 0x0A, 0x0D, 0x00, 0x0B] as $byte) {
                $checks[] = $context->builder->icmp(
                    Builder::INT_EQ,
                    $ch,
                    $i32->constInt($byte, false)
                );
            }
            $result = $checks[0];
            for ($i = 1; $i < count($checks); ++$i) {
                $result = $context->builder->or($result, $checks[$i]);
            }

            return $result;
        }

        $maskStr = $context->builder->call($context->lookupFunction('__string__separate'), $maskStr);
        $i32 = $context->getTypeFromString('int32');
        $inMask = $context->builder->call(
            $context->lookupFunction('__phpc_char_in_mask'),
            $ch,
            $maskStr
        );

        return $context->builder->icmp(Builder::INT_NE, $inMask, $i32->constInt(0, false));
    }

    /** trim/ltrim/rtrim/chop: coerced null/"" needs no php_trim loop (AOT-safe, #21404). */
    public static function jitReturnIfCoercedEmptyTrimInput(
        Context $context,
        JITVariable $arg,
        Value $strVal
    ): ?Value {
        if ($context->callerStrictTypes) {
            return null;
        }
        if (
            JITVariable::TYPE_NULL === $arg->type
            || $arg->isNullConstant
            || '' === ($arg->compileTimeString ?? null)
        ) {
            return $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $strVal
            );
        }

        return null;
    }

    /** php_trim — Zend 8.4 DEP+coerces null (not TypeError until 9.0); use soft-null path (#21404). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        return VmString::trimFamilyStringArgForFrame(
            $frame,
            $argIndex,
            'trim',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'trim',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'trim',
            $argIndex,
            $paramName
        );
    }
}
