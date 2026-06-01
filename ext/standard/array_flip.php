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
 * array_flip() for arrays with int or string keys and values (subset of PHP; JIT via ArrayBuiltinHelper).
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
                $out->updateIndex($val->toInt(), $stored);
            } else {
                $out->update($val->toString(), $stored);
            }
        }
        $frame->returnVar->array($out);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('array_flip() requires exactly one argument');
        }
        if (JITVariable::TYPE_HASHTABLE !== $args[0]->type
            && !($args[0]->type & JITVariable::IS_NATIVE_ARRAY)) {
            throw new \LogicException('array_flip() argument must be an array in this compiler build');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_flip() argument #'.((int) $i + 1));
            }
        }
        return ArrayBuiltinHelper::buildFlipArray($context, $args[0]);
    }
}
