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
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_combine() for two list arrays of equal length (subset of PHP; VM only).
 */
final class array_combine extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_combine() requires exactly two arguments');
        }
        $keysArg = $frame->calledArgs[0]->resolveIndirect();
        $valuesArg = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $keysArg->type || Variable::TYPE_ARRAY !== $valuesArg->type) {
            throw new \LogicException('array_combine() requires two arrays in this compiler build');
        }
        $keys = [];
        foreach ($keysArg->toArray()->iterateKeyed(true) as [, $key]) {
            $keys[] = $key;
        }
        $values = [];
        foreach ($valuesArg->toArray()->iterateKeyed(true) as [, $value]) {
            $values[] = $value;
        }
        if (\count($keys) !== \count($values)) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        $n = \count($keys);
        for ($i = 0; $i < $n; ++$i) {
            $key = $keys[$i];
            $stored = new Variable();
            $stored->copyFrom($values[$i]);
            if (Variable::TYPE_INTEGER === $key->type) {
                $ht->addIndex($key->toInt(), $stored);
            } elseif (Variable::TYPE_STRING === $key->type) {
                $ht->add($key->toString(), $stored);
            } else {
                throw new \LogicException('array_combine() keys must be integers or strings in this compiler build');
            }
        }
        $frame->returnVar->array($ht);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_combine() is not implemented for JIT in this compiler build');
    }
}
