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
use PHPCompiler\JIT\ExceptionBridge;
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
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28317).
        $this->requireExactArgCount($frame, 'strrev', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $subject = self::vmStringArg($frame, 0, 'string');
        $frame->returnVar->string(VmString::strrev($subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer htmlspecialchars #28285 / #28317.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('strrev() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }

        // Null operand: TypeError under strict_types; soft-null coerces to "" without
        // StringStrrev::ensureLinked (user-script AOT helper IR still clears insert block; #20007).
        // strrev("") === "" so returning the coerced empty string is correct.
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'strrev', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strrev', 0, 'string');
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

        // Soft-null — coerce+deprecate on forward profile (#20007, string.c).
        return VmString::coerceTrimFamilyStringArg(
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

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'strrev',
            $argIndex,
            $paramName
        );
    }
}
