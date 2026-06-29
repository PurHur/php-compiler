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
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * hash_pbkdf2() — sha256, sha1, md5 (VM + JIT/AOT via __compiler_hash_pbkdf2, issue #3773).
 *
 * php-src: ext/hash/hash_pbkdf2.c
 */
final class hash_pbkdf2 extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 6) {
            throw new \LogicException('hash_pbkdf2() requires four to six arguments in this compiler build');
        }
        $algo = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'hash_pbkdf2', 0, 'algo');
        $password = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'hash_pbkdf2', 1, 'password');
        $salt = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'hash_pbkdf2', 2, 'salt');
        $iterations = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'hash_pbkdf2', 4, 'iterations');
        $length = 0;
        if ($argc >= 5) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'hash_pbkdf2', 5, 'length');
        }
        $raw = false;
        if (6 === $argc) {
            $rawArg = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $rawArg->type) {
                throw new \LogicException('hash_pbkdf2() raw_output must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
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
        if (\count($args) < 4 || \count($args) > 6) {
            throw new \LogicException('hash_pbkdf2() requires four to six arguments in this compiler build');
        }
        $length = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[4])) {
            $length = JitLongArg::lower($context, $args[4], 'hash_pbkdf2() length');
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[5])) {
            $raw = JitBoolArg::lower($context, $args[5], 'hash_pbkdf2() raw_output');
        }

        return JitHash::hashPbkdf2(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'hash_pbkdf2', 0, 'algo'),
            JitStringBuiltinArg::lower($context, $args[1], 'hash_pbkdf2', 1, 'password'),
            JitStringBuiltinArg::lower($context, $args[2], 'hash_pbkdf2', 2, 'salt'),
            JitLongArg::lower($context, $args[3], 'hash_pbkdf2() iterations'),
            $length,
            $raw
        );
    }
}
