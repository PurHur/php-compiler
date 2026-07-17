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
        // Z_PARAM_STR $algo — null TypeError on 8.4 forward profile (#20304, ext/hash/hash.c).
        $algo = self::vmZparamStrArg($frame, 0, 'algo');
        $data = self::vmZparamStrArg($frame, 1, 'data');
        $key = self::vmZparamStrArg($frame, 2, 'key');
        $raw = false;
        if (4 === $argc) {
            $raw = VmMath::parseBoolBuiltinArg($frame->calledArgs[3], 'hash_hmac', 4, 'binary');
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
            $raw = JitBoolArg::lower($context, $args[3], 'hash_hmac(): Argument #4 ($binary)');
        }
        return JitHash::hashHmac(
            $context,
            self::jitZparamStrArg($context, $args[0], 0, 'algo'),
            self::jitZparamStrArg($context, $args[1], 1, 'data'),
            self::jitZparamStrArg($context, $args[2], 2, 'key'),
            $raw
        );
    }

    /**
     * Z_PARAM_STR $algo / $data / $key — null TypeError on 8.4 forward profile (#19275, #20175, #20304, ext/hash/hash.c).
     */
    private static function vmZparamStrArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, $argIndex, 'hash_hmac', $paramName);

            return $frame->calledArgs[$argIndex]->resolveIndirect()->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
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

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'hash_hmac',
            $argIndex,
            $paramName
        );
    }
}
