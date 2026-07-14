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
use PHPCompiler\JIT\Builtin\StringStrrev;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strrev() for strings (subset of PHP; byte reversal).
 *
 * VM: {@see VmString::strrev()}; JIT/AOT: {@see StringStrrev} + {@see StrrevJitHelper}.
 */
final class strrev extends Internal
{
    public function __construct()
    {
        parent::__construct('strrev');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('strrev() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $subject = InternalStrictArg::resolveCoercibleStringArg($frame, 0, 'strrev', 'string');
        $frame->returnVar->string(VmString::strrev($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== count($args)) {
            throw new \LogicException('strrev() requires exactly one argument');
        }

        StringStrrev::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strrev'),
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strrev', 0, 'string')
        );
    }
}
