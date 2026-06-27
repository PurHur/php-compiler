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
use PHPCompiler\JIT\Builtin\ArrayReplaceRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_replace() for arrays with int and string keys (subset of PHP; issue #1208).
 */
final class array_replace extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_replace() expects at least 1 argument, 0 given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $first = VmArray::requireArrayParam($frame->calledArgs[0], 'array_replace', 1, 'array');
        if (1 === $argc) {
            $frame->returnVar->array($first->replaceCopy());

            return;
        }
        $others = [];
        for ($i = 1, $n = $argc; $i < $n; ++$i) {
            $others[] = VmArray::requireArrayParam(
                $frame->calledArgs[$i],
                'array_replace',
                $i + 1,
                'array'
            );
        }
        $frame->returnVar->array($first->replaceCopy(...$others));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_replace() expects at least 1 argument, 0 given');
        }

        TypeErrorRaise::ensureLinked($context);
        foreach ($args as $i => $arg) {
            JitArrayElem::requireArrayParam($context, $arg, 'array_replace', $i + 1, 'array');
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_replace() argument #'.((int) $i + 1));
            }
        }

        return ArrayReplaceRuntime::replace($context, $args[0], ...\array_slice($args, 1));
    }
}
