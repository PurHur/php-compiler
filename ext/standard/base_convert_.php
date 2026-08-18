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
use PHPCompiler\JIT\Builtin\MathBaseConvert;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * base_convert() — arbitrary-base integer conversion (issue #3173).
 *
 * php-src: ext/standard/math.c — PHP_FUNCTION(base_convert)
 */
final class base_convert_ extends Internal
{
    public function __construct()
    {
        parent::__construct('base_convert');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#21982).
        $this->requireExactArgCount($frame, 'base_convert', 3);
        if (null === $frame->returnVar) {
            return;
        }
        // String soft-null DEP/TypeError indices are 0-based (VmNullStringParamDeprecation adds +1).
        // Passing 1 here cited parameter #2 ($num); Zend cites #1 (#29320). Int bases stay 1-based
        // (VmNullNumberParamDeprecation uses the display index as-is).
        $numStr = VmString::trimFamilyStringArgForFrame($frame, 0, 'base_convert', 0, 'num');
        $fromBase = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'base_convert', 2, 'from_base');
        $toBase = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'base_convert', 3, 'to_base');

        $frame->returnVar->string(VmMath::baseConvert(
            $numStr,
            $fromBase,
            $toBase
        ));
        if (VmMath::takeInvalidRadixCharsDeprecation()) {
            VmMathRadixDeprecation::emit($frame);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'base_convert', 3)) {
            return $context->builder->load($context->constantStringFromString(''));
        }
        $folded = self::tryFoldCompileTime($context, $args);
        if (null !== $folded) {
            return $folded;
        }
        $num = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'base_convert', 0, 'num')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'base_convert', 0, 'num');
        if (
            !$context->callerStrictTypes
            && ($args[0]->isNullConstant ?? false)
        ) {
            return JitValueBox::coerceToValuePtrForStore(
                $context,
                $context->builder->load($context->constantStringFromString('0'))
            );
        }
        MathBaseConvert::ensureLinked($context);
        $fromBase = $context->callerStrictTypes
            ? JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'base_convert', 2, 'from_base')
            : JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'base_convert', 2, 'from_base');
        $toBase = $context->callerStrictTypes
            ? JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[2], 'base_convert', 3, 'to_base')
            : JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'base_convert', 3, 'to_base');
        $fn = $context->lookupFunction('phpc_base_convert');

        return $context->builder->call($fn, $num, $fromBase, $toBase);
    }

    /**
     * All three args compile-time — Zend math.c at emit time (#31966).
     *
     * @param list<JITVariable> $args
     */
    private static function tryFoldCompileTime(Context $context, array $args): ?Value
    {
        $num = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $num && null !== $args[0]->compileTimeLong) {
            $num = (string) $args[0]->compileTimeLong;
        }
        if (null === $num) {
            return null;
        }
        $from = $args[1]->compileTimeLong;
        $to = $args[2]->compileTimeLong;
        if (null === $from || null === $to) {
            return null;
        }
        if ($from < 2 || $from > 36 || $to < 2 || $to > 36) {
            return null;
        }
        $out = VmMath::baseConvert($num, (int) $from, (int) $to);
        if (VmMath::takeInvalidRadixCharsDeprecation()) {
            return null;
        }

        return $context->builder->load($context->constantStringFromString($out));
    }
}
