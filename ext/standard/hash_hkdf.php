<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * hash_hkdf() — RFC 5869 HKDF (VM + JIT/AOT via __compiler_hash_hkdf, issue #5025).
 *
 * php-src: ext/hash/hash_hkdf.c / hash.stub.php — Z_PARAM_STR algo/key/info/salt (#19341, #21079).
 */
final class hash_hkdf extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 5) {
            throw new \LogicException('hash_hkdf() requires two to five arguments in this compiler build');
        }
        // Z_PARAM_STR $algo / $key — null TypeError on 8.4 forward (#21079); else coerce then empty-key ValueError (#19341).
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
        if (\count($args) < 2 || \count($args) > 5) {
            throw new \LogicException('hash_hkdf() requires two to five arguments in this compiler build');
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
            'hash_hkdf(): Argument #2 ($key) cannot be empty'
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
     * Z_PARAM_STR $algo / $key / $info / $salt — null TypeError on 8.4 forward (#21079, ext/hash/hash.stub.php).
     */
    private static function vmZparamStrArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, $argIndex, 'hash_hkdf', $paramName);

            return $frame->calledArgs[$argIndex]->resolveIndirect()->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
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

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'hash_hkdf',
            $argIndex,
            $paramName
        );
    }
}
