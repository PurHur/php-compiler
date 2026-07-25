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
use PHPCompiler\JIT\Builtin\ArrayPopRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_pop() for packed list arrays (subset of PHP).
 */
final class array_pop extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        $this->requireExactArgCount($frame, 'array_pop', 1);
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_pop', 1, 'array');
        $popped = ArrayPopJitHelper::pop($ht);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($popped);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        if (!$this->requireExactJitArgCount($context, $args, 'array_pop', 1)) {
            return $context->getTypeFromString('__value__*')->constNull();
        }
        JitArrayElem::requireArrayParam($context, $args[0], 'array_pop', 1, 'array');

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_pop() argument #'.((int) $i + 1));
            }
        }
        return ArrayPopRuntime::pop($context, $args[0]);
    }
}
