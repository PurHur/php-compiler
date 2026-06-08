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
use PHPCompiler\JIT\Builtin\PadTypeJit;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_padded() — UTF-8 codepoint padding (PHP 8.4, ext/standard/string.c / mb_str_pad semantics).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_str_pad); ext/standard/string.c — PHP_FUNCTION(str_padded)
 */
final class str_padded extends Internal
{
    private const FUNCTION = 'str_padded';

    public function __construct()
    {
        parent::__construct(self::FUNCTION);
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException(self::FUNCTION.'() requires two to four arguments');
        }
        $input = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            self::FUNCTION,
            0,
            'string'
        );
        $padLength = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $padLength->type) {
            throw new \LogicException(self::FUNCTION.'() pad length must be an integer in this compiler build');
        }
        $padString = ' ';
        if ($argc >= 3) {
            $padString = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                self::FUNCTION,
                2,
                'pad_string'
            );
        }
        $padType = 1;
        if (4 === $argc) {
            $padType = VmString::resolveStrPadTypeArg($frame->calledArgs[3]);
        }
        $result = VmString::strPadded($input, $padLength->toInt(), $padString, $padType);
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string($result)
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException(self::FUNCTION.'() requires two to four arguments');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException(self::FUNCTION.'() pad length must be an integer in this compiler build');
        }

        $inputLit = JITVariable::TYPE_STRING === $args[0]->type ? ($args[0]->compileTimeString ?? null) : null;
        $padLenLit = $args[1]->compileTimeInteger ?? null;
        $padStringLit = ' ';
        if ($argc >= 3) {
            $padStringLit = JITVariable::TYPE_STRING === $args[2]->type
                ? ($args[2]->compileTimeString ?? null)
                : null;
        }
        $padTypeLit = 1;
        if (4 === $argc) {
            $padTypeFromEnum = PadTypeJit::compileTimePadType($context, $args[3]);
            if (null !== $padTypeFromEnum) {
                $padTypeLit = $padTypeFromEnum;
            } elseif (JITVariable::TYPE_NATIVE_LONG === $args[3]->type && null !== ($args[3]->compileTimeInteger ?? null)) {
                $padTypeLit = $args[3]->compileTimeInteger;
            } else {
                $padTypeLit = null;
            }
        }
        if (null !== $inputLit && null !== $padLenLit && null !== $padStringLit && null !== $padTypeLit) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmString::strPadded($inputLit, $padLenLit, $padStringLit, $padTypeLit)
                )
            );
        }

        $input = JitStringBuiltinArg::lower($context, $args[0], self::FUNCTION, 0, 'string');
        $padLength = $context->helper->loadValue($args[1]);
        if ($argc >= 3) {
            $padString = JitStringBuiltinArg::lower($context, $args[2], self::FUNCTION, 2, 'pad_string');
        } else {
            $padString = $context->builder->load($context->constantStringFromString(' '));
        }
        if (4 === $argc) {
            $padTypeLiteral = PadTypeJit::compileTimePadType($context, $args[3]);
            if (null !== $padTypeLiteral) {
                $padType = $context->getTypeFromString('int64')->constInt($padTypeLiteral, false);
            } else {
                $padType = JitLongArg::lower($context, $args[3], self::FUNCTION.'() pad type');
            }
        } else {
            $padType = $context->getTypeFromString('int64')->constInt(1, false);
        }
        JitStrPad::emitRuntimeEmptyPadStringGuard($context, $padString);

        return JitStrPad::pad($context, $input, $padLength, $padString, $padType);
    }
}
