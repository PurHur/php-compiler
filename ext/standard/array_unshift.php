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
use PHPCompiler\JIT\Builtin\ArrayUnshiftRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_unshift() prepending one or more values (subset of PHP; packed list arrays).
 */
final class array_unshift extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('array_unshift() requires at least one argument');
        }
        $ht = VmArray::requireArrayParam($frame->calledArgs[0], 'array_unshift', 1, 'array');
        if (\count($frame->calledArgs) < 2) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->int(ArrayUnshiftJitHelper::countElements($ht));

            return;
        }
        $values = [];
        for ($i = 1, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $values[] = $frame->calledArgs[$i]->resolveIndirect();
        }
        $count = ArrayUnshiftJitHelper::unshift($ht, ...$values);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($count);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('array_unshift() requires at least one argument');
        }
        JitArrayElem::requireArrayParam($context, $args[0], 'array_unshift', 1, 'array');
        $array = $args[0];
        $values = \array_slice($args, 1);

        foreach ($values as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_unshift() argument #'.((int) $i + 1));
            }
        }

        return ArrayUnshiftRuntime::unshift($context, $array, ...$values);
    }
}
