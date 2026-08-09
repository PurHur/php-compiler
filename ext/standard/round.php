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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * round() for integer or float arguments with optional precision and mode (php-src math.c).
 */
final class round extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/math.c — ArgumentCountError (#28229, peer #25407).
        $this->requireArgCountRange($frame, 'round', 1, 3);

        $num = VmMath::parseNumberBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'round',
            1,
            'num',
            $frame
        );
        $numVar = new Variable();
        if (\is_int($num)) {
            $numVar->int($num);
        } else {
            $numVar->float($num);
        }

        $precision = 0;
        if (isset($frame->calledArgs[1])) {
            $precision = self::vmPrecisionArg($frame);
        }

        $mode = StdlibConstants::PHP_ROUND_HALF_UP;
        if (isset($frame->calledArgs[2])) {
            $mode = VmRoundMode::resolveRoundModeArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'round',
                'mode',
                3,
                $frame
            );
        }

        if (null === $frame->returnVar) {
            return;
        }

        VmRound::apply($frame->returnVar, $numVar, $precision, $mode);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT try/catch) — peer sort() #23855 / #28229.
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('round() expects at least 1 argument, %d given', $argc)
                    : \sprintf('round() expects at most 3 arguments, %d given', $argc)
            );

            return $context->getTypeFromString('double')->constReal(0.0);
        }

        return JitRound::round($context, ...$args);
    }

    private static function vmPrecisionArg(Frame $frame): int
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            return InternalStrictArg::requireInt($frame, 1, 'round', 'precision')->toInt();
        }

        return VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'round',
            2,
            'precision'
        );
    }
}
