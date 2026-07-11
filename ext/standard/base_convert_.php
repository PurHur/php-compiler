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
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
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
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('base_convert() requires exactly three arguments in this compiler build');
        }
        $numVar = $frame->calledArgs[0]->resolveIndirect();
        $fromVar = $frame->calledArgs[1]->resolveIndirect();
        $toVar = $frame->calledArgs[2]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $numStr = VmString::stringBuiltinArgForFrame($frame, 0, 'base_convert', 0, 'num');
        if (Variable::TYPE_INTEGER !== $fromVar->type || Variable::TYPE_INTEGER !== $toVar->type) {
            throw new \LogicException('base_convert() base arguments must be integers in this compiler build');
        }

        $frame->returnVar->string(VmMath::baseConvert(
            $numStr,
            $fromVar->toInt(),
            $toVar->toInt()
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        MathBaseConvert::ensureLinked($context);
        if (3 !== \count($args)) {
            throw new \LogicException('base_convert() requires exactly three arguments in this compiler build');
        }
        $ptr = $this->stringDataPtr(
            $context,
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'base_convert', 0, 'num')
        );
        $fromBase = $this->jitLong($context, $args[1], 'base_convert() argument #2');
        $toBase = $this->jitLong($context, $args[2], 'base_convert() argument #3');
        $fn = $context->lookupFunction('phpc_base_convert');

        return $context->builder->call($fn, $ptr, $fromBase, $toBase);
    }
}
