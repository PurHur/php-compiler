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
use PHPCompiler\JIT\Builtin\MathFpow;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fpow() — IEEE-754 floating power (PHP 8.4, ext/standard/math.c / zend_fpow).
 *
 * php-src arity is exactly 2 — no rounding_mode (#23577; re-#9990 phantom).
 */
final class fpow extends Internal
{
    private const FUNCTION = 'fpow';

    public function __construct()
    {
        parent::__construct(self::FUNCTION);
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, self::FUNCTION, 2);
        // Z_PARAM_DOUBLE: strict_types TypeError; else soft-null DEP+coerce (#30021, peers #29782).
        $num = VmMath::parseStrictFloatBuiltinArgForFrame(
            $frame,
            self::FUNCTION,
            1,
            'num'
        );
        $exponent = VmMath::parseStrictFloatBuiltinArgForFrame(
            $frame,
            self::FUNCTION,
            2,
            'exponent'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(VmMath::fpow($num, $exponent));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, self::FUNCTION, 2)) {
            return $context->getTypeFromString('double')->constReal(0.0);
        }
        [$base, $exp] = JitFdiv::lowerOperands(
            $context,
            $args[0],
            $args[1],
            self::FUNCTION,
            'num',
            'exponent',
            'float',
            false
        );

        return MathFpow::invoke($context, $base, $exp);
    }
}
