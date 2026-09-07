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
 * str_rot13() for strings (subset of PHP; ASCII letters only).
 *
 * VM: {@see VmString::strRot13()}; JIT/AOT: separate + in-place transform (#36388).
 */
final class str_rot13 extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28313).
        $this->requireExactArgCount($frame, 'str_rot13', 1);
        $subject = self::vmStringArg($frame, 0, 'string');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::strRot13($subject))
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT try/catch) — peer basename #28286 / #28313.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('str_rot13() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }

        // In-place ROT13 after separate — avoids NestedJIT `$out .=` leaks (#36388).
        // php-src: ext/standard/string.c PHP_FUNCTION(str_rot13) / php_strtr_ex letter map.
        $str = self::jitStringArg($context, $args[0], 0, 'string');
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        JitStringBuiltinArg::releaseEphemeralArgAfterCopy($context, $args[0], $str);
        self::transformRot13InPlace($context, $copy);

        return $copy;
    }

    /**
     * ASCII letter ROT13 into a mutable {@see __string__*} (rc=1 after separate).
     *
     * php-src: ext/standard/string.c — PHP_FUNCTION(str_rot13).
     */
    private static function transformRot13InPlace(Context $context, Value $strPtr): void
    {
        $map = $context->structFieldsFor($strPtr);
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $iSlot = $context->builder->alloca($i64, 1, 'rot13_i');
        $context->builder->store($zero, $iSlot);

        $done = BasicBlockHelper::append($context, 'rot13_done');
        $loopHead = BasicBlockHelper::append($context, 'rot13_head');
        $loopBody = BasicBlockHelper::append($context, 'rot13_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $atChar = $context->builder->gep($charPtr, $i);
        $ch = $context->builder->load($atChar);
        $chI32 = $context->builder->zExt($ch, $i32);
        $plus = $context->builder->addNoSignedWrap($chI32, $i32->constInt(13, false));
        $minus = $context->builder->sub($chI32, $i32->constInt(13, false));
        // A-M / a-m → +13; N-Z / n-z → -13; else unchanged.
        $isAm = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(77, false))
        );
        $isAmLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(109, false))
        );
        $isNz = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(78, false)),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(90, false))
        );
        $isNzLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(110, false)),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(122, false))
        );
        $doPlus = $context->builder->or($isAm, $isAmLower);
        $doMinus = $context->builder->or($isNz, $isNzLower);
        $afterPlus = $context->builder->select($doPlus, $plus, $chI32);
        $afterMinus = $context->builder->select($doMinus, $minus, $afterPlus);
        $newCh = $context->builder->truncOrBitCast($afterMinus, $ch->typeOf());
        $context->builder->store($newCh, $atChar);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'str_rot13', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21280; reverts #19309 TypeError).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'str_rot13',
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
                'str_rot13',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'str_rot13',
            $argIndex,
            $paramName
        );
    }
}
