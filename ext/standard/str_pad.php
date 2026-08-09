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
use PHPCompiler\JIT\Builtin\StringStrPad;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_pad() for strings (STR_PAD_LEFT, STR_PAD_RIGHT, STR_PAD_BOTH).
 *
 * VM: {@see VmString::strPad()}; JIT/AOT: {@see StringStrPad} + {@see StrPadJitHelper}
 * (helper inlines pad logic — do not call VmString from NestedJIT, #23911 / peer #23204).
 */
final class str_pad extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireArgCountRange($frame, 'str_pad', 2, 4);
        $argc = \count($frame->calledArgs);
        $input = self::vmStringArg($frame, 0, 'string');
        $padLength = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'str_pad', 2, 'length');
        $padString = ' ';
        if (isset($frame->calledArgs[2])) {
            $padString = self::vmStringArg($frame, 2, 'pad_string');
        }
        // Compiler convention: 0 = STR_PAD_LEFT, 1 = STR_PAD_RIGHT (default).
        // Explicit null uses Z_PARAM_LONG soft-null → 0 (STR_PAD_LEFT), not the omitted default (#29353).
        $padType = 1;
        if (isset($frame->calledArgs[3])) {
            $padType = VmString::resolveStrPadTypeArg($frame->calledArgs[3], $frame);
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
        if (!$this->requireArgCountRangeJit($context, $args, 'str_pad', 2, 4)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        $input = self::jitStringArg($context, $args[0], 0, 'string');
        $padLength = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'str_pad', 2, 'length');
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $padString = self::jitStringArg($context, $args[2], 2, 'pad_string');
        } else {
            $padString = $context->builder->load($context->constantStringFromString(' '));
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            $padTypeLiteral = PadTypeJit::compileTimePadType($context, $args[3]);
            if (null !== $padTypeLiteral) {
                $padType = $context->getTypeFromString('int64')->constInt($padTypeLiteral, false);
            } else {
                // Z_PARAM_LONG soft-null DEP+coerce (peer mb_str_pad / #29353).
                $padType = JitIntdiv::lowerIntBuiltinArg($context, $args[3], 'str_pad', 4, 'pad_type');
            }
        } else {
            $padType = $context->getTypeFromString('int64')->constInt(1, false);
        }
        StringStrPad::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_str_pad'),
            $input,
            $padLength,
            $padString,
            $padType
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'str_pad', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21190).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'str_pad',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'str_pad',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'str_pad',
            $argIndex,
            $paramName
        );
    }
}
