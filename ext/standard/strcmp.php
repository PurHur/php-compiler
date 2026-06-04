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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

final class strcmp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('strcmp() requires exactly two arguments');
        }
        $a = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'strcmp', 0, 'string1');
        $b = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'strcmp', 1, 'string2');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::strcmp($a, $b));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('strcmp() requires exactly two arguments');
        }
        $p0 = $this->stringDataPtr($context, JitStrcmp::lowerStringOperand($context, $args[0], 1, 'string1'));
        $p1 = $this->stringDataPtr($context, JitStrcmp::lowerStringOperand($context, $args[1], 2, 'string2'));
        $fn = $context->lookupFunction('strcmp');
        $raw = $context->builder->call($fn, $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }
}
