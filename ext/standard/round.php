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
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('round() requires one to three arguments');
        }

        $num = VmMath::parseNumberBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'round',
            1,
            'num'
        );
        $numVar = new Variable();
        if (\is_int($num)) {
            $numVar->int($num);
        } else {
            $numVar->float($num);
        }

        $precision = 0;
        if ($argc >= 2) {
            $precision = self::vmPrecisionArg($frame);
        }

        $mode = StdlibConstants::PHP_ROUND_HALF_UP;
        if (3 === $argc) {
            $mode = VmRoundMode::resolveRoundModeArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'round'
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
