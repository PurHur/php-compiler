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
use PHPCompiler\JIT\Builtin\ArrayKeysRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitArrayElem;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_keys() for list arrays (subset of PHP; JIT via ArrayKeysRuntime PHP bridge).
 */
final class array_keys extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('array_keys() requires one to three arguments');
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_keys');
        if (null === $frame->returnVar) {
            return;
        }
        if (1 === $argc) {
            $frame->returnVar->array($ht->keysCopy());

            return;
        }
        $searchValue = $frame->calledArgs[1]->resolveIndirect();
        $strict = false;
        if (3 === $argc) {
            $strict = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $frame->returnVar->array($ht->keysMatchingCopy($searchValue, $strict));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('array_keys() requires one to three arguments');
        }
        if ($argc > 1) {
            $strict = $context->constantFromBool(false);
            if (3 === $argc) {
                $strict = JitBoolArg::lower($context, $args[2], 'array_keys() strict');
            }
            if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
                $this->jitString($context, $args[1], 'array_keys() search_value');
            }

            return ArrayKeysRuntime::keysFiltered($context, $args[0], $args[1], $strict);
        }

        if (JITVariable::TYPE_HASHTABLE === $args[0]->type
            || ($args[0]->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return ArrayKeysRuntime::keys($context, $args[0]);
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            JitArrayElem::requireArrayArg($context, $args[0], 'array_keys');

            return ArrayKeysRuntime::keys($context, $args[0]);
        }
        JitArrayElem::requireArrayArg($context, $args[0], 'array_keys');

        return ArrayKeysRuntime::keys($context, $args[0]);
    }
}
