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
use PHPCompiler\JIT\Builtin\ArrayFlipRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_flip() for arrays with int or string keys and values (subset of PHP; JIT via ArrayFlipRuntime).
 *
 * VM: {@see VmArray::flip()}; JIT/AOT: {@see ArrayFlipRuntime::flip()}.
 */
final class array_flip extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_flip() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_flip', 1, 'array');
        $frame->returnVar->array(VmArray::flip($ht, $frame));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('array_flip() requires exactly one argument');
        }
        TypeErrorRaise::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'array_flip', 1, 'array');

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_flip() argument #'.((int) $i + 1));
            }
        }
        TypeErrorRaise::ensureLinked($context);

        return ArrayFlipRuntime::flip($context, $args[0]);
    }
}
