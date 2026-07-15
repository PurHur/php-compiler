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
use PHPCompiler\JIT\ArrayFindCallbackPolicy;
use PHPCompiler\JIT\ArrayFindHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * array_find_key() — key of first element matching a predicate (PHP 8.4; ext/standard/array.c).
 */
final class array_find_key extends Internal
{
    public function execute(Frame $frame): void
    {
        $strict = VmArrayValueCallback::parseOptionalStrictArg($frame->calledArgs, 'array_find_key');
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_find_key');
        $callback = $frame->calledArgs[1];
        VmArrayValueCallback::requireCallback($frame, $callback, 'array_find_key');
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $result = VmArrayValueCallback::invokePredicate($frame, $callback, $value, $key, 'array_find_key');
            if (VmArrayValueCallback::predicateMatches($result, $strict)) {
                $frame->returnVar->copyFrom($key);

                return;
            }
        }
        $frame->returnVar->null();
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_find_key() requires two or three arguments in this compiler build');
        }
        if ($args[1]->isNullConstant) {
            throw new \TypeError(ArrayFindCallbackPolicy::invalidCallbackTypeError('array_find_key'));
        }
        if (!ArrayFindCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_find_key() callback');
        }
        if (3 === $argc) {
            $strictI1 = $this->jitBool($context, $args[2], 'array_find_key() strict');

            return ArrayFindHelper::buildFindKeyArray($context, $args[0], $args[1], null, $strictI1);
        }

        return ArrayFindHelper::buildFindKeyArray($context, $args[0], $args[1]);
    }
}
