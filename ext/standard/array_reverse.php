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
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_reverse() — packed lists and associative arrays (ext/standard/array.c; #4335).
 */
final class array_reverse extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_reverse() requires one or two arguments in this compiler build');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_reverse() argument must be an array in this compiler build');
        }
        $preserveKeys = false;
        if (2 === $argc) {
            $preserveKeys = $frame->calledArgs[1]->resolveIndirect()->toBool();
        }
        $frame->returnVar->array($array->toArray()->reverseCopy($preserveKeys));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('array_reverse() requires one or two arguments in this compiler build');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_reverse() argument #'.((int) $i + 1));
            }
        }
        $preserveKeys = 2 === $argc
            ? JitBoolArg::lower($context, $args[1], 'array_reverse() preserve_keys')
            : null;

        return ArrayBuiltinHelper::buildReverseArray($context, $args[0], $preserveKeys);
    }
}
