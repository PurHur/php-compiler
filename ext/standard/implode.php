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

/**
 * implode() with glue and array of scalar values (subset of PHP; VM only).
 */
final class implode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('implode() requires exactly two arguments in this compiler build');
        }
        $glue = $frame->calledArgs[0]->resolveIndirect()->toString();
        $array = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('implode() second argument must be an array in this compiler build');
        }
        $parts = [];
        foreach ($array->toArray()->iterate(true) as $value) {
            $parts[] = $value->resolveIndirect()->toString();
        }
        $frame->returnVar->string(VmString::implode($glue, $parts));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('implode() requires exactly two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('implode() glue must be a string in this compiler build');
        }
        if (JITVariable::TYPE_HASHTABLE !== $args[1]->type) {
            throw new \LogicException('implode() second argument must be an array in this compiler build');
        }
        $glue = $context->helper->loadValue($args[0]);
        $haystack = $context->helper->loadValue($args[1]);

        return JitImplode::implode($context, $glue, $haystack);
    }
}
