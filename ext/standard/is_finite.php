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
use PHPCompiler\JIT\JitIsFinite;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * is_finite() for float arguments; integers are always finite (subset of PHP standard library).
 */
final class is_finite extends Internal
{
    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'is_finite', 1);
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER === $v->type) {
            $frame->returnVar->bool(true);

            return;
        }
        $num = VmMath::parseStrictFloatBuiltinArgForFrame($frame, 'is_finite', 1, 'num');
        $frame->returnVar->bool(\is_finite($num));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'is_finite', 1)) {
            return $context->constantFromBool(false);
        }
        if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type) {
            return $context->constantFromBool(true);
        }
        $asFloat = JitFdiv::lowerSingleOperand($context, $args[0], 1, 'num', 'is_finite', 'float');

        return JitIsFinite::lower($context, $asFloat);
    }
}
