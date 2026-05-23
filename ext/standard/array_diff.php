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
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_diff() for arrays of scalar values (loose compare; subset of PHP; issue #1206).
 */
final class array_diff extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('array_diff() requires at least two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $first->type) {
            throw new \LogicException('array_diff() first argument must be an array in this compiler build');
        }
        $others = [];
        for ($i = 1, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $arg = $frame->calledArgs[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \LogicException('array_diff() arguments must be arrays in this compiler build');
            }
            $others[] = $arg->toArray();
        }
        $out = new HashTable();
        foreach ($first->toArray()->iterateKeyed(true) as [$key, $value]) {
            if (self::valueInArrays($value, $others)) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $stored);
            } else {
                $out->add($key->toString(), $stored);
            }
        }
        $frame->returnVar->array($out);
    }

    /**
     * @param list<\PHPCompiler\VM\HashTable> $arrays
     */
    private static function valueInArrays(Variable $needle, array $arrays): bool
    {
        $needle = $needle->resolveIndirect();
        foreach ($arrays as $haystack) {
            foreach ($haystack->iterate(true) as $value) {
                $stored = $value->resolveIndirect();
                if (in_array::looseEquals($needle, $stored)) {
                    return true;
                }
            }
        }

        return false;
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('array_diff() requires at least two arguments');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_diff() argument #'.((int) $i + 1));
            }
        }

        return ArrayBuiltinHelper::arrayDiff($context, ...$args);
    }
}
