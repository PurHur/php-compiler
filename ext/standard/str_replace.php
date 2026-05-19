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
 * str_replace() with string search, replace, and subject (subset of PHP; LLVM JIT/AOT).
 */
final class str_replace extends Internal
{
    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_replace() requires exactly three arguments in this compiler build');
        }
        $search = $frame->calledArgs[0]->resolveIndirect();
        $replace = $frame->calledArgs[1]->resolveIndirect();
        $subject = $frame->calledArgs[2]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $search->type
            || Variable::TYPE_STRING !== $replace->type
            || Variable::TYPE_STRING !== $subject->type) {
            throw new \LogicException('str_replace() requires string arguments in this compiler build');
        }
        $frame->returnVar->string(VmString::strReplace(
            $search->toString(),
            $replace->toString(),
            $subject->toString()
        ));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (3 !== \count($args)) {
            throw new \LogicException('str_replace() requires exactly three arguments in this compiler build');
        }
        foreach ($args as $arg) {
            if (JITVariable::TYPE_STRING !== $arg->type && JITVariable::TYPE_VALUE !== $arg->type) {
                throw new \LogicException('str_replace() requires string arguments in this compiler build');
            }
        }

        return JitStrReplace::replace(
            $context,
            self::jitStringArg($context, $args[0]),
            self::jitStringArg($context, $args[1]),
            self::jitStringArg($context, $args[2])
        );
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

        throw new \LogicException('str_replace() requires string arguments in this compiler build');
    }
}
