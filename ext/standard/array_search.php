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
        $haystack = VmArray::requireArrayParam(
            $frame->calledArgs[1],
            'array_search',
            2,
            'haystack'
        );
        $strict = false;
        if (3 === $argc) {
            $strict = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $vm = null !== $frame->vmContext ? $frame->vmContext->runtime->vm() : null;
        if (null === $frame->returnVar) {
            return;
        }
        foreach ($haystack->iterateKeyed(true) as [$key, $value]) {
            if ($strict ? $needle->identicalTo($value) : in_array::looseEquals($needle, $value, $vm)) {
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
            $strict = JitBoolArg::lower($context, $args[2], 'array_search() strict');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type || JITVariable::TYPE_VALUE === $args[0]->type) {
            $this->jitString($context, $args[0], 'array_search() needle');
        }
        JitArrayElem::requireArrayParam($context, $args[1], 'array_search', 2, 'haystack');

        return ArrayBuiltinHelper::arraySearch($context, $args[0], $args[1], $strict);
    }
}
