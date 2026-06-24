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
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
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
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_diff() expects at least 1 argument, 0 given');
        }
        $firstHt = VmArray::requireArrayArgNum(
            $frame->calledArgs[0]->resolveIndirect(),
            'array_diff',
            1
        );
        $operandTables = [$firstHt];
        if (1 === $argc) {
            VmArray::rejectEnumCaseSetOpOperands($frame, $firstHt);
            if (null !== $frame->returnVar) {
                $frame->returnVar->array($firstHt->replaceCopy());
            }

            return;
        }
        $others = [];
        for ($i = 1, $n = $argc; $i < $n; ++$i) {
            $others[] = VmArray::requireArrayArgNum(
                $frame->calledArgs[$i]->resolveIndirect(),
                'array_diff',
                $i + 1
            );
            $operandTables[] = $others[\count($others) - 1];
        }
        VmArray::rejectEnumCaseSetOpOperands($frame, ...$operandTables);
        if (null === $frame->returnVar) {
            return;
        }
        $out = new HashTable();
        foreach ($firstHt->iterateKeyed(true) as [$key, $value]) {
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
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_diff() expects at least 1 argument, 0 given');
        }

        TypeErrorRaise::ensureLinked($context);
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_diff() argument #'.((int) $i + 1));
            }
            JitArrayElem::requireArrayArgNum($context, $arg, 'array_diff', $i + 1);
        }

        return ArrayBuiltinHelper::arrayDiff($context, ...$args);
    }
}
