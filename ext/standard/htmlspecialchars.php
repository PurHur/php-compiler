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
use PHPCompiler\JIT\Builtin\StringHtmlspecialchars;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/**
 * htmlspecialchars() for strings (subset of PHP; JIT UTF-8 + flags + double_encode — #27290).
 *
 * php-src: ext/standard/html.stub.php / html.c — PHP_FUNCTION(htmlspecialchars)
 */
final class htmlspecialchars extends Internal
{
    private const DEFAULT_FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

    public function execute(Frame $frame): void
    {
        // php-src zend_API.c / html.stub.php — ArgumentCountError (#28285, peer #28284).
        $this->requireArgCountRange($frame, 'htmlspecialchars', 1, 4);
        $string = self::vmStringArg($frame, 0, 'string');
        $flags = self::DEFAULT_FLAGS;
        $encoding = 'UTF-8';
        $doubleEncode = true;
        if (isset($frame->calledArgs[1])) {
            $flags = VmMath::parseZParamLongBuiltinArg(
                $frame->calledArgs[1],
                'htmlspecialchars',
                2,
                'flags',
                $frame
            );
        }
        if (isset($frame->calledArgs[2])) {
            $encoding = self::resolveEncodingVm($frame->calledArgs[2]->resolveIndirect());
        }
        if (isset($frame->calledArgs[3])) {
            // Z_PARAM_BOOL — null→false + E_DEPRECATED (php-src html.c / zend_API.h; #29445).
            $doubleEncode = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                3,
                'htmlspecialchars',
                4,
                'double_encode'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::htmlspecialchars($string, $flags, $encoding, $doubleEncode));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer array_search #28284 / #28285.
        // Allocate typed return before AndAbort (constNull after a sealed block is invalid IR).
        if ($argc < 1 || $argc > 4) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('htmlspecialchars() expects at least 1 argument, %d given', $argc)
                    : \sprintf('htmlspecialchars() expects at most 4 arguments, %d given', $argc)
            );

            return $unreachable;
        }

        $folded = self::tryCompileTimeHtmlspecialchars($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        self::assertJitUtf8Encoding($args, $argc);

        $hasDoubleEncode = $argc >= 4;
        $effectiveFlagsArgc = self::jitEffectiveFlagsArgc($argc, $args);

        $literal = null;
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            $maybeLiteral = $args[0]->compileTimeString ?? null;
            // Fold proven compile-time strings: KIND_VALUE immediates and TYPE_STRING
            // stack slots (literal args / assigned locals keep KIND_VARIABLE with
            // compileTimeString). Avoids the helper-runtime object for MiniWebApp
            // titles (#25345). Catch-reassign paths clear compileTimeString (#2387).
            if (null !== $maybeLiteral && self::isCompileTimeFoldableString($args[0])) {
                $literal = $maybeLiteral;
            }
        }
        if (null !== $literal && 1 === $effectiveFlagsArgc && !$hasDoubleEncode) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmString::htmlspecialchars($literal, self::DEFAULT_FLAGS, 'UTF-8', true)
                )
            );
        }

        StringHtmlspecialchars::ensureLinked($context);

        $str = self::jitStringArg($context, $args[0], 0, 'string');
        $flags = $context->getTypeFromString('int64')->constInt(self::DEFAULT_FLAGS, false);
        if ($effectiveFlagsArgc >= 2 || $argc >= 2) {
            $flags = JitLongArg::lower($context, $args[1], 'htmlspecialchars() flags');
        }

        if (!$hasDoubleEncode) {
            return JitHtmlspecialchars::escape($context, $str, $flags);
        }

        $doubleEncode = self::jitDoubleEncodeArg($context, $args[3]);

        return JitHtmlspecialchars::escapeEx($context, $str, $flags, $doubleEncode);
    }

    /** Zend 8.4 DEP+coerces null (not TypeError until 9.0); use soft-null path (#21405). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'htmlspecialchars', $paramName)->toString();
        }

        return VmString::trimFamilyStringArgForFrame(
            $frame,
            $argIndex,
            'htmlspecialchars',
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
                'htmlspecialchars',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'htmlspecialchars',
            $argIndex,
            $paramName
        );
    }

    private static function resolveEncodingVm(VMVariable $encVar): string
    {
        if (VMVariable::TYPE_NULL === $encVar->type) {
            return 'UTF-8';
        }
        if (VMVariable::TYPE_STRING !== $encVar->type) {
            throw new \TypeError(
                'htmlspecialchars(): Argument #3 ($encoding) must be of type ?string, '
                .self::vmTypeName($encVar->type).' given'
            );
        }

        return $encVar->toString();
    }

    /**
     * Argc for flags-only fast path: null encoding does not count (#27290).
     *
     * @param list<JITVariable> $args
     */
    private static function jitEffectiveFlagsArgc(int $argc, array $args): int
    {
        if ($argc >= 3 && self::encodingArgIsNull($args[2]) && $argc < 4) {
            return 2;
        }

        return $argc >= 2 ? 2 : $argc;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function assertJitUtf8Encoding(array $args, int $argc): void
    {
        if ($argc < 3 || self::encodingArgIsNull($args[2])) {
            return;
        }
        $encodingLit = JitStringArg::compileTimeLiteral($args[2]);
        if (null === $encodingLit) {
            throw new \LogicException(
                'htmlspecialchars() JIT encoding must be a compile-time string in this compiler build'
            );
        }
        if (0 !== strcasecmp($encodingLit, 'UTF-8')) {
            throw new \LogicException(
                'htmlspecialchars() JIT only supports UTF-8 encoding in this compiler build'
            );
        }
    }

    private static function jitDoubleEncodeArg(Context $context, JITVariable $arg): Value
    {
        // Soft-null needs runtime DEP — do not fold TYPE_NULL (#29445 / peer wordwrap #29354).
        if (JITVariable::TYPE_NULL !== $arg->type && !$arg->isNullConstant) {
            $folded = self::compileTimeBool($context, $arg);
            if (null !== $folded) {
                return $context->getTypeFromString('int64')->constInt($folded ? 1 : 0, false);
            }
        }
        // Z_PARAM_BOOL — null→false + E_DEPRECATED (php-src html.c; #29445).
        $bool = JitBoolArg::lowerCoerceZParamBool(
            $context,
            $arg,
            'htmlspecialchars',
            'double_encode',
            4
        );

        return $context->builder->zExt($bool, $context->getTypeFromString('int64'));
    }

    private static function encodingArgIsNull(JITVariable $var): bool
    {
        return JITVariable::TYPE_NULL === $var->type || $var->isNullConstant;
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            VMVariable::TYPE_INTEGER => 'int',
            VMVariable::TYPE_FLOAT => 'float',
            VMVariable::TYPE_BOOLEAN => 'bool',
            VMVariable::TYPE_ARRAY => 'array',
            VMVariable::TYPE_OBJECT => 'object',
            VMVariable::TYPE_RESOURCE => 'resource',
            default => 'unknown type',
        };
    }

    /**
     * Proven compile-time string for htmlspecialchars fold (#25345).
     *
     * Literal call args and assigned locals are often TYPE_STRING + KIND_VARIABLE with
     * compileTimeString set; requiring KIND_VALUE alone skipped the fold.
     */
    private static function isCompileTimeFoldableString(JITVariable $arg): bool
    {
        if (null === ($arg->compileTimeString ?? null)) {
            return false;
        }
        if (JITVariable::KIND_VALUE === $arg->kind) {
            return true;
        }

        return JITVariable::TYPE_STRING === $arg->type
            && JITVariable::KIND_VARIABLE === $arg->kind;
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeHtmlspecialchars(Context $context, array $args): ?Value
    {
        $argc = count($args);
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $literal || !self::isCompileTimeFoldableString($args[0])) {
            return null;
        }

        $flags = self::DEFAULT_FLAGS;
        if ($argc >= 2) {
            $flagsVal = self::compileTimeLong($context, $args[1]);
            if (null === $flagsVal) {
                return null;
            }
            $flags = $flagsVal;
        }

        $encoding = 'UTF-8';
        if ($argc >= 3 && !self::encodingArgIsNull($args[2])) {
            $encodingLit = JitStringArg::compileTimeLiteral($args[2]);
            if (null === $encodingLit || JITVariable::KIND_VALUE !== $args[2]->kind) {
                return null;
            }
            $encoding = $encodingLit;
        }

        $doubleEncode = true;
        if ($argc >= 4) {
            // Soft-null needs runtime DEP — refuse compile-time fold (#29445).
            if (JITVariable::TYPE_NULL === $args[3]->type || $args[3]->isNullConstant) {
                return null;
            }
            $doubleEncodeVal = self::compileTimeBool($context, $args[3]);
            if (null === $doubleEncodeVal) {
                return null;
            }
            $doubleEncode = $doubleEncodeVal;
        }

        return $context->builder->load(
            $context->constantStringFromString(
                VmString::htmlspecialchars($literal, $flags, $encoding, $doubleEncode)
            )
        );
    }

    private static function compileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (null !== $var->compileTimeLong) {
            return (int) $var->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $var->type
            && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }
        if (null !== $var->compileTimeConstantName && null !== $context->runtime->vmContext) {
            $phpVar = $context->runtime->vmContext->constantFetch($var->compileTimeConstantName);
            if (null !== $phpVar && VMVariable::TYPE_INTEGER === $phpVar->resolveIndirect()->type) {
                return $phpVar->resolveIndirect()->toInt();
            }
        }

        return null;
    }

    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        if (null !== $var->compileTimeLong) {
            return 0 !== $var->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type
            && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
            }
        }
        $name = $var->compileTimeConstantName ?? null;
        if (null !== $name) {
            $lc = strtolower($name);
            if ('true' === $lc) {
                return true;
            }
            if ('false' === $lc) {
                return false;
            }
        }
        if (null !== $name && null !== $context->runtime->vmContext) {
            $phpVar = $context->runtime->vmContext->constantFetch($name);
            if (null !== $phpVar && VMVariable::TYPE_BOOLEAN === $phpVar->resolveIndirect()->type) {
                return $phpVar->resolveIndirect()->toBool();
            }
        }

        return null;
    }

}
