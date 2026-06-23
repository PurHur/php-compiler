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
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class strcmp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('strcmp() requires exactly two arguments');
        }
        $a = VmString::requireStringBuiltinArg($frame->calledArgs[0], 'strcmp', 0, 'string1');
        $b = VmString::requireStringBuiltinArg($frame->calledArgs[1], 'strcmp', 1, 'string2');
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
        $left = JitStringBuiltinArg::lowerRequiredString($context, $args[0], 'strcmp', 0, 'string1');
        $right = JitStringBuiltinArg::lowerRequiredString($context, $args[1], 'strcmp', 1, 'string2');

        return JitStringCompare::strcmp($context, $left, $right);
    }
}
