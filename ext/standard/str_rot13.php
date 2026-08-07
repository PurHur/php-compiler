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
use PHPCompiler\JIT\Builtin\StringStrRot13;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * str_rot13() for strings (subset of PHP; ASCII letters only).
 *
 * VM: {@see VmString::strRot13()}; JIT/AOT: {@see StringStrRot13} + {@see StrRot13JitHelper}.
 */
final class str_rot13 extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.stub.php — ArgumentCountError (#28313).
        $this->requireExactArgCount($frame, 'str_rot13', 1);
        $subject = self::vmStringArg($frame, 0, 'string');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::strRot13($subject))
        );
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        // Catchable ArgumentCountError (AOT try/catch) — peer basename #28286 / #28313.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('str_rot13() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }

        $str = self::jitStringArg($context, $args[0], 0, 'string');
        StringStrRot13::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_str_rot13'),
            $str
        );
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'str_rot13', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21280; reverts #19309 TypeError).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'str_rot13',
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
                'str_rot13',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'str_rot13',
            $argIndex,
            $paramName
        );
    }
}
