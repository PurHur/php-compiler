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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_search() for arrays of scalar values (subset of PHP).
 */
final class array_search extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc && 3 !== $argc) {
            throw new \LogicException('array_search() requires two or three arguments');
        }
        $needle = $frame->calledArgs[0]->resolveIndirect();
        $haystack = $frame->calledArgs[1]->resolveIndirect();
        $strict = false;
        if (3 === $argc) {
            $strict = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $haystack->type) {
            throw new \LogicException('array_search() second argument must be an array in this compiler build');
        }
        foreach ($haystack->toArray()->iterateKeyed(true) as [$key, $value]) {
            if ($strict ? $needle->identicalTo($value) : in_array::looseEquals($needle, $value)) {
                $frame->returnVar->copyFrom($key);

                return;
            }
        }
        $frame->returnVar->bool(false);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc && 3 !== $argc) {
            throw new \LogicException('array_search() requires two or three arguments');
        }
        $strict = $context->constantFromBool(false);
        if (3 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL !== $args[2]->type) {
                throw new \LogicException('array_search() strict flag must be boolean in this compiler build');
            }
            $strict = $context->helper->loadValue($args[2]);
        }

        return ArrayBuiltinHelper::arraySearch($context, $args[0], $args[1], $strict);
    }
}
