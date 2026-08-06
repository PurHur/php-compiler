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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * lcfirst() for strings (subset of PHP; ASCII letters only in JIT).
 */
final class lcfirst extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28317).
        $this->requireExactArgCount($frame, 'lcfirst', 1);
        $subject = self::vmStringArg($frame);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::asciiLcfirst($subject))
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT try/catch) — peer htmlspecialchars #28285 / #28317.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('lcfirst() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }
        $str = self::jitStringArg($context, $args[0]);
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        self::transformFirstAscii($context, $copy, ord('A'), ord('Z'), 32);

        return $copy;
    }

    public static function transformFirstAscii(Context $context, Value $strPtr, int $letterMin, int $letterMax, int $delta): void
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        $done = BasicBlockHelper::append($context, 'case_transform_done');
        $work = BasicBlockHelper::append($context, 'case_transform_work');
        $context->builder->branchIf($isEmpty, $done, $work);

        $context->builder->positionAtEnd($work);
        $valGep = $context->builder->structGep($strPtr, $map['value']);
        $ch = $context->builder->load($valGep);
        $i32 = $context->builder->zExt($ch, $context->getTypeFromString('int32'));
        $min = $i32->typeOf()->constInt($letterMin, false);
        $max = $i32->typeOf()->constInt($letterMax, false);
        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $i32, $min),
            $context->builder->icmp(Builder::INT_SLE, $i32, $max)
        );
        $offset = $i32->typeOf()->constInt($delta, false);
        $adjusted = $context->builder->addNoSignedWrap($i32, $offset);
        $newCh = $context->builder->truncOrBitCast(
            $context->builder->select($inRange, $adjusted, $i32),
            $ch->typeOf()
        );
        $context->builder->store($newCh, $valGep);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    public static function transformAllAscii(Context $context, Value $strPtr, int $letterMin, int $letterMax, int $delta): void
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $iSlot = $context->builder->alloca($i64, 1, 'case_transform_i');
        $context->builder->store($zero, $iSlot);

        $done = BasicBlockHelper::append($context, 'case_transform_all_done');
        $loopHead = BasicBlockHelper::append($context, 'case_transform_all_head');
        $loopBody = BasicBlockHelper::append($context, 'case_transform_all_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $i32 = $context->getTypeFromString('int32');
        $atChar = $context->builder->gep($charPtr, $i);
        $ch = $context->builder->load($atChar);
        $chI32 = $context->builder->zExt($ch, $i32);
        $min = $i32->constInt($letterMin, false);
        $max = $i32->constInt($letterMax, false);
        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $min),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $max)
        );
        $offset = $i32->constInt($delta, false);
        $adjusted = $context->builder->addNoSignedWrap($chI32, $offset);
        $newCh = $context->builder->truncOrBitCast(
            $context->builder->select($inRange, $adjusted, $chI32),
            $ch->typeOf()
        );
        $context->builder->store($newCh, $atChar);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }

    private static function vmStringArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'lcfirst', 'string')->toString();
        }

        // Soft-null — coerce+deprecate on forward profile (#24598, reverts #24213; string.c).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[0],
            'lcfirst',
            0,
            'string'
        );
    }

    /** Soft-null DEP+coerce on forward profile (#24598, reverts #24213; ext/standard/string.c). */
    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible($context, $arg, 'lcfirst', 0, 'string');
        }

        return JitStringBuiltinArg::lowerTrimFamilyString($context, $arg, 'lcfirst', 0, 'string');
    }
}
