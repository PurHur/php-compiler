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
use PHPCompiler\JIT\HashTableHelper;
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
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        $this->requireArgCountRange($frame, 'array_keys', 1, 3);
        $argc = \count($frame->calledArgs);
        // php-src 8.0+: Z_PARAM_ARRAY — always TypeError on null (not soft-coerce).
        // Zend 8.2 reference matches; do not gate on caller strict_types (#21915, re-#21771).
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
        // php-src ext/standard/array.c — ArgumentCountError (#23165).
        if (!$this->requireArgCountRangeJit($context, $args, 'array_keys', 1, 3)) {
            return HashTableHelper::emptyVariable($context)->value;
        }
        $argc = \count($args);
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            // php-src 8.0+: always TypeError on null (#21915).
            JitArrayElem::requireArrayArg($context, $args[0], 'array_keys');

            return HashTableHelper::emptyVariable($context)->value;
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
