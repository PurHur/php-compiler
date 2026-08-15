<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * hash_pbkdf2() — sha256, sha1, md5 (VM + JIT/AOT via __compiler_hash_pbkdf2, issue #3773).
 *
 * php-src: ext/hash/hash.c / hash.stub.php — Z_PARAM_STR algo/password/salt;
 * optional array $options (passed to hash_init; unused for sha256/sha1/md5) — #23595.
 * Non-strict null is E_DEPRECATED + '' on 8.4 (re-#20659 / #21319); strict_types still TypeErrors.
 */
final class hash_pbkdf2 extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4) {
            throw new \ArgumentCountError(\sprintf(
                'hash_pbkdf2() expects at least 4 arguments, %d given',
                $argc
            ));
        }
        if (self::maxCalledArgIndex($frame->calledArgs) > 6) {
            throw new \ArgumentCountError(\sprintf(
                'hash_pbkdf2() expects at most 7 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $algo / $password / $salt — soft-null DEP+coerce on 8.4 (#21319).
        $algo = self::vmZparamStrArg($frame, 0, 'algo');
        $password = self::vmZparamStrArg($frame, 1, 'password');
        $salt = self::vmZparamStrArg($frame, 2, 'salt');
        $iterations = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'hash_pbkdf2', 4, 'iterations');
        $length = 0;
        if (isset($frame->calledArgs[4])) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'hash_pbkdf2', 5, 'length');
        }
        $raw = false;
        if (isset($frame->calledArgs[5])) {
            // Z_PARAM_BOOL: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31288).
            $raw = VmMath::parseBoolBuiltinArgForFrame($frame, 5, 'hash_pbkdf2', 6, 'binary');
        }
        if (isset($frame->calledArgs[6])) {
            // Z_PARAM_ARRAY $options — stub parity; unused for sha256/sha1/md5 (#23595).
            VmArray::requireArrayParam($frame->calledArgs[6], 'hash_pbkdf2', 7, 'options');
        }
        if ($iterations < 1) {
            throw new \ValueError('hash_pbkdf2(): Argument #4 ($iterations) must be greater than 0');
        }
        if ($length < 0) {
            throw new \ValueError('hash_pbkdf2(): Argument #5 ($length) must be greater than or equal to 0');
        }
        $algoName = strtolower($algo);
        if (!\in_array($algoName, ['sha256', 'sha1', 'md5'], true)) {
            throw new \ValueError(
                'hash_pbkdf2(): Argument #1 ($algo) must be a valid cryptographic hashing algorithm'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmHash::hashPbkdf2(
            $algo,
            $password,
            $salt,
            $iterations,
            $length,
            $raw
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 4) {
            throw new \ArgumentCountError(\sprintf(
                'hash_pbkdf2() expects at least 4 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 7) {
            throw new \ArgumentCountError(\sprintf(
                'hash_pbkdf2() expects at most 7 arguments, %d given',
                $argc
            ));
        }
        $length = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[4])) {
            $length = JitLongArg::lower($context, $args[4], 'hash_pbkdf2() length');
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[5])) {
            // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31288).
            $raw = JitBoolArg::lowerCoerceZParamBool($context, $args[5], 'hash_pbkdf2', 'binary', 6);
        }
        if (isset($args[6])) {
            // Z_PARAM_ARRAY $options — type-checked; unused for sha256/sha1/md5 (#23595).
            JitArrayElem::requireArrayParam($context, $args[6], 'hash_pbkdf2', 7, 'options');
        }

        return JitHash::hashPbkdf2(
            $context,
            self::jitZparamStrArg($context, $args[0], 0, 'algo'),
            self::jitZparamStrArg($context, $args[1], 1, 'password'),
            self::jitZparamStrArg($context, $args[2], 2, 'salt'),
            JitLongArg::lower($context, $args[3], 'hash_pbkdf2() iterations'),
            $length,
            $raw
        );
    }

    /**
     * @param array<int, \PHPCompiler\VM\Variable> $calledArgs
     */
    private static function maxCalledArgIndex(array $calledArgs): int
    {
        if ([] === $calledArgs) {
            return -1;
        }

        return max(array_keys($calledArgs));
    }

    /**
     * Z_PARAM_STR $algo / $password / $salt — non-strict null is E_DEPRECATED + '' on 8.4 (#21319).
     */
    private static function vmZparamStrArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, $argIndex, 'hash_pbkdf2', $paramName);

            return $frame->calledArgs[$argIndex]->resolveIndirect()->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'hash_pbkdf2',
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
                'hash_pbkdf2',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'hash_pbkdf2',
            $argIndex,
            $paramName
        );
    }
}
