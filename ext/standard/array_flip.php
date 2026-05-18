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
 * array_flip() for arrays with int or string keys and values (subset of PHP; VM only).
 */
final class array_flip extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_flip() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_flip() argument must be an array in this compiler build');
        }
        $out = new HashTable();
        foreach ($array->toArray()->iterateKeyed(true) as [$key, $value]) {
            $val = $value->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $val->type && Variable::TYPE_STRING !== $val->type) {
                throw new \LogicException('array_flip() values must be integers or strings in this compiler build');
            }
            $stored = new Variable();
            $stored->copyFrom($key);
            if (Variable::TYPE_INTEGER === $val->type) {
                $out->addIndex($val->toInt(), $stored);
            } else {
                $out->add($val->toString(), $stored);
            }
        }
        $frame->returnVar->array($out);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_flip() is not implemented for JIT in this compiler build');
    }
}
