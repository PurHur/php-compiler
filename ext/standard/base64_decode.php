<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringBase64Decode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * base64_decode() — RFC 4648 decode with optional $strict (php-src ext/standard/base64.c).
 *
 * Two-arg form returns string|false. Bare `__string__*` / int1 under AOT mis-lower in
 * `$x === false ? '' : $x` (SIGSEGV / empty) — box like {@see JitLocale} / #34802.
 * Fold `$strict` via ConstFetch / native i1 (no JIT Variable::$compileTimeBool).
 */
final class base64_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('base64_decode');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/base64.stub.php — ArgumentCountError (#28316).
        $this->requireArgCountRange($frame, 'base64_decode', 1, 2);
        $argc = \count($frame->calledArgs);
        $data = self::vmStringArg($frame);
        // Z_PARAM_BOOL — strict_types TypeError; else null→false + E_DEPRECATED (php-src base64.c; #29867).
        $strict = false;
        if (2 === $argc) {
            $strict = VmMath::parseBoolBuiltinArgForFrame($frame, 1, 'base64_decode', 2, 'strict');
        }
        $result = VmString::base64_decode($data, $strict);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);
            } else {
                $ret->string($result);
            }
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer #28317 / #28316.
        if ($argc < 1 || $argc > 2) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('base64_decode() expects at least 1 argument, %d given', $argc)
                    : \sprintf('base64_decode() expects at most 2 arguments, %d given', $argc)
            );

            return $unreachable;
        }
        $strictConst = false;
        if (2 === $argc) {
            // Compile-time non-bool under strict: TypeError then stop IR (#29867).
            // Continuing past abort leaves a terminator mid-block under AOT (substr_compare #29756).
            if ($context->callerStrictTypes && self::isCompileTimeNonBoolStrict($args[1])) {
                // Mirror requireInt: ensure insert block before TypeError+abort (#29779 / #29867).
                JitNativeString::ensureInsertBlock($context);
                JitInternalStrictArg::requireBool($context, $args[1], 'base64_decode', 'strict', 2);

                return $context->constantFromBool(false);
            }
            // Soft-null DEP+coerce outside strict; helper ABI is non-strict-only (#26890).
            JitBoolArg::lowerCoerceZParamBool($context, $args[1], 'base64_decode', 'strict', 2);
            $ct = self::compileTimeBool($context, $args[1]);
            if (null !== $ct) {
                $strictConst = $ct;
            } elseif (self::isCompileTimeNull($args[1])) {
                $strictConst = false;
            }
        }
        // Null → soft-coerce to "" without helper IR (base64_decode("") === ""; #21188).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'base64_decode', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'base64_decode', 0, 'string');
        }
        // Match encode: fold compile-time literals (JitStringArg fallback — #26890).
        $literal = null;
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            $literal = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        }
        $strictKnown = 1 === $argc
            || (2 === $argc && (
                null !== self::compileTimeBool($context, $args[1])
                || self::isCompileTimeNull($args[1])
            ));
        if (null !== $literal && $strictKnown) {
            $result = VmString::base64_decode($literal, $strictConst);
            // 2-arg: string|false must be a `__value__*` for === false / ?: (#34802).
            if (2 === $argc) {
                return self::boxStringOrFalse($context, $result);
            }
            if (false === $result) {
                return $context->constantFromBool(false);
            }

            return $context->builder->load(
                $context->constantStringFromString($result)
            );
        }

        StringBase64Decode::ensureLinked($context);
        $decoded = $context->builder->call(
            $context->lookupFunction('__compiler_base64_decode'),
            self::jitStringArg($context, $args[0])
        );
        // Runtime helper is non-strict (decodeArgv); still box when argc==2 so
        // string|false call sites do not treat `__string__*` as a value box (#34802).
        if (2 === $argc) {
            return self::boxStringPtrOrFalse($context, $decoded);
        }

        return $decoded;
    }

    /** @param string|false $result */
    private static function boxStringOrFalse(Context $context, $result): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $result) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }
        $str = $context->builder->load($context->constantStringFromString($result));
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }

    /**
     * Box a non-null `__string__*` as string (2-arg call site typed string|false).
     * Helper never returns null today; keep a null→false edge for ABI honesty.
     */
    private static function boxStringPtrOrFalse(Context $context, Value $strOrNull): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $strOrNull, $strPtr->constNull());
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $fail = BasicBlockHelper::append($context, 'base64_dec_box_false');
        $ok = BasicBlockHelper::append($context, 'base64_dec_box_str');
        $done = BasicBlockHelper::append($context, 'base64_dec_box_done');
        $context->builder->branchIf($isNull, $fail, $ok);

        $context->builder->positionAtEnd($fail);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($done);

        $context->builder->positionAtEnd($ok);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $strOrNull
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $ptr;
    }

    /** Soft-null — coerce+deprecate on forward profile (#21188, ext/standard/base64.c). */
    private static function vmStringArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'base64_decode', 'string')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[0],
            'base64_decode',
            0,
            'string'
        );
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'base64_decode',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'base64_decode',
            0,
            'string'
        );
    }

    private static function isCompileTimeNull(JITVariable $arg): bool
    {
        return JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false);
    }

    /**
     * Fold true/false / ConstFetch / native i1 — peer json_decode (#24137).
     * There is no JIT Variable::$compileTimeBool; that property never existed.
     */
    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        if (null !== $var->compileTimeConstantName) {
            $name = strtolower($var->compileTimeConstantName);
            if ('true' === $name) {
                return true;
            }
            if ('false' === $name) {
                return false;
            }
        }
        if (null !== $var->compileTimeLong) {
            return 0 !== $var->compileTimeLong;
        }
        if (null === $var->value) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type
            && null !== $lib->LLVMIsAConstantInt($var->value->value)) {
            return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
        }

        return null;
    }

    /** Compile-time operand that cannot satisfy Z_PARAM_BOOL under strict_types. */
    private static function isCompileTimeNonBoolStrict(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            return false;
        }
        if (null !== $arg->compileTimeConstantName) {
            $name = strtolower($arg->compileTimeConstantName);
            if ('true' === $name || 'false' === $name) {
                return false;
            }
        }
        if (JITVariable::TYPE_VALUE === $arg->type && !self::isCompileTimeNull($arg)) {
            return false;
        }

        return true;
    }
}
