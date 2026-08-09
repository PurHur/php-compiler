<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * hash_hkdf() — RFC 5869 HKDF (VM + JIT/AOT via __compiler_hash_hkdf, issue #5025).
 *
 * php-src: ext/hash/hash_hkdf.c / hash.stub.php — Z_PARAM_STR algo/key/info/salt.
 * Non-strict null is E_DEPRECATED + '' on 8.4 (re-#21079 / #21319); empty key → ValueError (#19341).
 * Excess argc → ArgumentCountError (#28315).
 */
final class hash_hkdf extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/hash/hash.stub.php — ArgumentCountError (#28315).
        $this->requireArgCountRange($frame, 'hash_hkdf', 2, 5);
        $argc = \count($frame->calledArgs);
        // Soft-null then empty-key ValueError (#21319 / #19341).
        $algo = self::vmZparamStrArg($frame, 0, 'algo');
        $key = self::vmZparamStrArg($frame, 1, 'key');
        VmString::rejectEmptyBuiltinStringArg($key, 'hash_hkdf', 1, 'key');
        $length = 0;
        if ($argc >= 3) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'hash_hkdf', 3, 'length');
            if ($length < 0) {
                throw new \ValueError('hash_hkdf(): Argument #3 ($length) must be greater than or equal to 0');
            }
        }
        $info = '';
        if ($argc >= 4) {
            $info = self::vmZparamStrArg($frame, 3, 'info');
        }
        $salt = '';
        if (5 === $argc) {
            $salt = self::vmZparamStrArg($frame, 4, 'salt');
        }
        $algoName = strtolower($algo);
        if (!\in_array($algoName, ['sha256', 'sha1', 'md5'], true)) {
            throw new \ValueError(
                'hash_hkdf(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmHash::hashHkdf(
            $algo,
            $key,
            $length,
            $info,
            $salt
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer hash_equals #28315.
        if ($argc < 2 || $argc > 5) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 2
                    ? \sprintf('hash_hkdf() expects at least 2 arguments, %d given', $argc)
                    : \sprintf('hash_hkdf() expects at most 5 arguments, %d given', $argc)
            );

            return $slot;
        }
        $length = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[2])) {
            $length = JitLongArg::lower($context, $args[2], 'hash_hkdf() length');
        }
        // Omitted $info / $salt default to "" (php-src hash.stub.php); do not invent a JITVariable.
        $info = isset($args[3])
            ? self::jitZparamStrArg($context, $args[3], 3, 'info')
            : $context->builder->load($context->constantStringFromString(''));
        $salt = isset($args[4])
            ? self::jitZparamStrArg($context, $args[4], 4, 'salt')
            : $context->builder->load($context->constantStringFromString(''));
        $key = self::jitZparamStrArg($context, $args[1], 1, 'key');
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[1],
            $key,
            'hash_hkdf(): Argument #2 ($key) must not be empty'
        );

        return JitHash::hashHkdf(
            $context,
            self::jitZparamStrArg($context, $args[0], 0, 'algo'),
            $key,
            $length,
            $info,
            $salt
        );
    }

    /**
     * Z_PARAM_STR $algo / $key / $info / $salt — non-strict null is E_DEPRECATED + '' on 8.4 (#21319).
     */
    private static function vmZparamStrArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, $argIndex, 'hash_hkdf', $paramName);

            return $frame->calledArgs[$argIndex]->resolveIndirect()->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'hash_hkdf',
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
                'hash_hkdf',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'hash_hkdf',
            $argIndex,
            $paramName
        );
    }
}
