<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** hash_hmac() — sha256, sha1, md5 (VM + JIT/AOT via __compiler_hash_hmac). */
final class hash_hmac extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'hash_hmac() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'hash_hmac() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        // Z_PARAM_STR $algo — non-strict null is E_DEPRECATED + '' then ValueError (#21490, reverts #20304).
        $algo = self::vmAlgoArg($frame);
        $data = self::vmDataArg($frame);
        // Z_PARAM_STR $key — non-strict null is E_DEPRECATED + '' on 8.4 (#21557, reverts #20175).
        $key = self::vmZparamStrArg($frame, 2, 'key');
        $raw = false;
        if (4 === $argc) {
            // Z_PARAM_BOOL: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31288).
            $raw = VmMath::parseBoolBuiltinArgForFrame($frame, 3, 'hash_hmac', 4, 'binary');
        }
        $frame->returnVar->string(VmHash::hashHmac($algo, $data, $key, $raw));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'hash_hmac() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'hash_hmac() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[3])) {
            // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31288).
            $raw = JitBoolArg::lowerCoerceZParamBool($context, $args[3], 'hash_hmac', 'binary', 4);
        }
        return JitHash::hashHmac(
            $context,
            self::jitAlgoArg($context, $args[0]),
            self::jitDataArg($context, $args[1]),
            self::jitZparamStrArg($context, $args[2], 2, 'key'),
            $raw
        );
    }

    /**
     * Z_PARAM_STR $algo — soft-null then ValueError on empty/unknown (#21490, ext/hash/hash.c).
     */
    private static function vmAlgoArg(Frame $frame): string
    {
        return VmString::trimFamilyStringArgForFrame($frame, 0, 'hash_hmac', 0, 'algo');
    }

    private static function jitAlgoArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'hash_hmac',
                0,
                'algo'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'hash_hmac',
            0,
            'algo'
        );
    }

    /**
     * Z_PARAM_STR $data — non-strict null is E_DEPRECATED + '' on 8.4 (php-src hash.c / #21181, #21209).
     */
    private static function vmDataArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, 1, 'hash_hmac', 'data');

            return $frame->calledArgs[1]->resolveIndirect()->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[1],
            'hash_hmac',
            1,
            'data'
        );
    }

    private static function jitDataArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'hash_hmac',
                1,
                'data'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'hash_hmac',
            1,
            'data'
        );
    }

    /**
     * Z_PARAM_STR $key — non-strict null is E_DEPRECATED + '' on 8.4 (#21557, reverts #20175).
     */
    private static function vmZparamStrArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, $argIndex, 'hash_hmac', $paramName);

            return $frame->calledArgs[$argIndex]->resolveIndirect()->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'hash_hmac',
            $argIndex,
            $paramName
        );
    }

    private static function jitZparamStrArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'hash_hmac',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'hash_hmac',
            $argIndex,
            $paramName
        );
    }
}
