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
use PHPCompiler\JIT\Builtin\StringStrrev;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strrev() for strings (subset of PHP; byte reversal).
 *
 * VM: {@see VmString::strrev()}; JIT/AOT: {@see StringStrrev} + {@see StrrevJitHelper}.
 */
final class strrev extends Internal
{
    public function __construct()
    {
        parent::__construct('strrev');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('strrev() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $subject = self::vmStringArg($frame, 0, 'string');
        $frame->returnVar->string(VmString::strrev($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== count($args)) {
            throw new \LogicException('strrev() requires exactly one argument');
        }

        // Early TypeError return before StringStrrev::ensureLinked (AOT helper IR gap; #19276).
        if (
            (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
            && ($context->callerStrictTypes || JitStringBuiltinArg::requiresZparamStrStrictNullOnForwardProfile())
        ) {
            JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'strrev', 0, 'string');

            return $context->getTypeFromString('__string__*')->constNull();
        }

        StringStrrev::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strrev'),
            self::jitStringArg($context, $args[0], 0, 'string')
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strrev', $paramName)->toString();
        }

        // Z_PARAM_STR — null TypeError on 8.4 forward profile (#19276, string.c).
        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$argIndex],
            'strrev',
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
                'strrev',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'strrev',
            $argIndex,
            $paramName
        );
    }
}
