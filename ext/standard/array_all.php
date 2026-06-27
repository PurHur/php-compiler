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
 * array_all() — true when every element matches a predicate (PHP 8.4; ext/standard/array.c).
 */
final class array_all extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_all() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_all');
        $callback = $frame->calledArgs[1];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $result = VmArrayValueCallback::invokePredicate($frame, $callback, $value, $key);
            if (!VmArrayValueCallback::isTruthy($result)) {
                $frame->returnVar->bool(false);

                return;
            }
        }
        $frame->returnVar->bool(true);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('array_all() requires exactly two arguments in this compiler build');
        }
        if (!ArrayFindCallbackPolicy::isJitLowerable($args[1])) {
            throw new \LogicException(ArrayFindCallbackPolicy::jitRejectionMessage());
        }
        if (JITVariable::TYPE_STRING === $args[1]->type || JITVariable::TYPE_VALUE === $args[1]->type) {
            $this->jitString($context, $args[1], 'array_all() callback');
        }

        return ArrayFindHelper::buildAllArray($context, $args[0], $args[1]);
    }
}
