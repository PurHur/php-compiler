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
use PHPCompiler\JIT\Builtin\StringMemcmp;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * memcmp() — binary-safe length-limited string compare (JIT via NCompareJitHelper PHP #15364).
 */
final class memcmp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (3 !== count($frame->calledArgs)) {
            throw new \LogicException('memcmp() requires exactly three arguments');
        }
        $a = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'memcmp', 0, 'string1');
        $b = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'memcmp', 1, 'string2');
        $len = $frame->calledArgs[2]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $len->type) {
            throw new \LogicException('memcmp() requires two strings and an integer length in this compiler build');
        }
        $frame->returnVar->int(VmString::memcmp($a, $b, $len->toInt()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (3 !== count($args)) {
            throw new \LogicException('memcmp() requires exactly three arguments');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('memcmp() length must be an integer in this compiler build');
        }
        StringMemcmp::ensureLinked($context);
        $left = JitStringBuiltinArg::lower($context, $args[0], 'memcmp', 0, 'string1');
        $right = JitStringBuiltinArg::lower($context, $args[1], 'memcmp', 1, 'string2');
        $length = JitLongArg::lower($context, $args[2], 'memcmp() length');

        return StringMemcmp::invoke($context, $left, $right, $length);
    }
}
