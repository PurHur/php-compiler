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
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * strpos() for two strings (subset of PHP; non-empty needle, no offset in JIT).
 */
final class strpos extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('strpos() requires two or three arguments');
        }
        $haystack = $frame->calledArgs[0]->resolveIndirect();
        $needle = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $haystack->type || Variable::TYPE_STRING !== $needle->type) {
            throw new \LogicException('strpos() only supports strings in this compiler build');
        }
        $offset = 0;
        if (3 === $argc) {
            $offVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $offVar->type) {
                throw new \LogicException('strpos() offset must be an integer in this compiler build');
            }
            $offset = $offVar->toInt();
        }
        $result = VmString::strpos($haystack->toString(), $needle->toString(), $offset);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($result);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('strpos() requires two or three arguments');
        }
        if (3 === $argc && JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('strpos() offset must be an integer in this compiler build');
        }

        $hay = self::jitStringArg($context, $args[0]);
        $needle = self::jitStringArg($context, $args[1]);
        $offset = 3 === $argc
            ? $context->builder->truncOrBitCast($context->helper->loadValue($args[2]), $context->getTypeFromString('int64'))
            : null;

        return JitStrpos::find($context, $hay, $needle, $offset);
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );
        }

        throw new \LogicException('strpos() only supports strings in this compiler build');
    }
}
