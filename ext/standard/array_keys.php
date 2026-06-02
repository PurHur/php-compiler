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
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_keys() for list arrays (subset of PHP; JIT via ArrayBuiltinHelper).
 */
final class array_keys extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_keys() requires exactly one argument');
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_keys');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array($ht->keysCopy());
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('array_keys() requires exactly one argument');
        }

        if (JITVariable::TYPE_HASHTABLE === $args[0]->type
            || ($args[0]->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return ArrayBuiltinHelper::buildKeysArrayFromVariable($context, $args[0]);
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            JitArrayElem::requireArrayArg($context, $args[0], 'array_keys');

            return ArrayBuiltinHelper::buildKeysArrayFromVariable($context, $args[0]);
        }
        JitArrayElem::requireArrayArg($context, $args[0], 'array_keys');

        return ArrayBuiltinHelper::buildKeysArray($context, HashTableHelper::alloc($context));
    }
}
