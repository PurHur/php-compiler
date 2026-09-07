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
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * strrev() for strings (subset of PHP; byte reversal).
 *
 * VM: {@see VmString::strrev()}; JIT/AOT: separate + in-place reverse (#36388).
 */
final class strrev extends Internal
{
    public function __construct()
    {
        parent::__construct('strrev');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28317).
        $this->requireExactArgCount($frame, 'strrev', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $subject = self::vmStringArg($frame, 0, 'string');
        $frame->returnVar->string(VmString::strrev($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer htmlspecialchars #28285 / #28317.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('strrev() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }

        // Null operand: TypeError under strict_types; soft-null coerces to "" without
        // NestedJIT helper IR (user-script AOT helper IR still clears insert block; #20007).
        // strrev("") === "" so returning the coerced empty string is correct.
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strrev', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strrev', 0, 'string');
        }

        // In-place reverse after separate — avoids NestedJIT `$out .=` leaks (#36388).
        // php-src: ext/standard/string.c PHP_FUNCTION(strrev) (allocate + reverse copy).
        $str = self::jitStringArg($context, $args[0], 0, 'string');
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        JitStringBuiltinArg::releaseEphemeralArgAfterCopy($context, $args[0], $str);
        self::reverseBytesInPlace($context, $copy);

        return $copy;
    }

    /**
     * Swap bytes in a mutable {@see __string__*} (rc=1 after separate).
     *
     * php-src: ext/standard/string.c PHP_FUNCTION(strrev) — reverse into a fresh zend_string.
     */
    private static function reverseBytesInPlace(Context $context, Value $strPtr): void
    {
        $map = $context->structFieldsFor($strPtr);
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $iSlot = $context->builder->alloca($i64, 1, 'strrev_i');
        $jSlot = $context->builder->alloca($i64, 1, 'strrev_j');
        $context->builder->store($zero, $iSlot);
        $context->builder->store($context->builder->sub($len, $one), $jSlot);

        $done = BasicBlockHelper::append($context, 'strrev_done');
        $loopHead = BasicBlockHelper::append($context, 'strrev_head');
        $loopBody = BasicBlockHelper::append($context, 'strrev_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $j = $context->builder->load($jSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $j);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $leftGep = $context->builder->gep($charPtr, $i);
        $rightGep = $context->builder->gep($charPtr, $j);
        $left = $context->builder->load($leftGep);
        $right = $context->builder->load($rightGep);
        $context->builder->store($right, $leftGep);
        $context->builder->store($left, $rightGep);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->store($context->builder->sub($j, $one), $jSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strrev', $paramName)->toString();
        }

        // Soft-null — coerce+deprecate on forward profile (#20007, string.c).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'strrev',
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
                'strrev',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'strrev',
            $argIndex,
            $paramName
        );
    }
}
