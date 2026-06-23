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
 * str_pad() for strings (STR_PAD_LEFT, STR_PAD_RIGHT, STR_PAD_BOTH).
 */
final class str_pad extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('str_pad() requires two to four arguments');
        }
        $input = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'str_pad',
            0,
            'string'
        );
        $padLength = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'str_pad', 2, 'length');
        $padString = ' ';
        if ($argc >= 3) {
            $padString = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'str_pad',
                2,
                'pad_string'
            );
        }
        // Compiler convention: 0 = STR_PAD_LEFT, 1 = STR_PAD_RIGHT (default).
        $padType = 1;
        if (4 === $argc) {
            $padType = VmString::resolveStrPadTypeArg($frame->calledArgs[3]);
        }
        $result = VmString::strPad($input, $padLength, $padString, $padType);
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
            throw new \LogicException('str_pad() requires two to four arguments');
        }
        $input = JitStringBuiltinArg::lower($context, $args[0], 'str_pad', 0, 'string');
        $padLength = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'str_pad', 2, 'length');
        if ($argc >= 3) {
            $padString = JitStringBuiltinArg::lower($context, $args[2], 'str_pad', 2, 'pad_string');
        } else {
            $padString = $context->builder->load($context->constantStringFromString(' '));
        }
        if (4 === $argc) {
            $padTypeLiteral = PadTypeJit::compileTimePadType($context, $args[3]);
            if (null !== $padTypeLiteral) {
                $padType = $context->getTypeFromString('int64')->constInt($padTypeLiteral, false);
            } else {
                $padType = JitLongArg::lower($context, $args[3], 'str_pad() pad type');
            }
        } else {
            $padType = $context->getTypeFromString('int64')->constInt(1, false);
        }
        JitStrPad::emitRuntimeEmptyPadStringGuard($context, $padString);

        return JitStrPad::pad($context, $input, $padLength, $padString, $padType);
    }
}
