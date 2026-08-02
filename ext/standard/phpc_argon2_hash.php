<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal libargon2 hash kernel for PasswordJitHelper NestedJIT (#26773).
 *
 * VM: {@see VmPasswordNative}; JIT/AOT NestedJIT leaf: {@see JitArgon2Kernel}.
 * php-src: ext/standard/password.c — argon2_hash
 */
final class phpc_argon2_hash extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_argon2_hash');
    }

    public function execute(Frame $frame): void
    {
        if (6 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                'phpc_argon2_hash() requires exactly six arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $password = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'phpc_argon2_hash', 0, 'password');
        $type = VmMath::parseIntBuiltinArg($frame->calledArgs[1]->resolveIndirect(), 'phpc_argon2_hash', 1, 'type');
        $memory = VmMath::parseIntBuiltinArg($frame->calledArgs[2]->resolveIndirect(), 'phpc_argon2_hash', 2, 'memory_cost');
        $time = VmMath::parseIntBuiltinArg($frame->calledArgs[3]->resolveIndirect(), 'phpc_argon2_hash', 3, 'time_cost');
        $threads = VmMath::parseIntBuiltinArg($frame->calledArgs[4]->resolveIndirect(), 'phpc_argon2_hash', 4, 'threads');
        // Salt is NestedJIT-only; VM execute regenerates via VmPasswordNative.
        unset($frame->calledArgs[5]);
        $algo = 1 === $type ? VmPassword::PASSWORD_ARGON2I : VmPassword::PASSWORD_ARGON2ID;
        $result = VmPasswordNative::passwordHash($password, $algo, [
            'memory_cost' => $memory,
            'time_cost' => $time,
            'threads' => $threads,
        ]);
        if (false === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (6 !== \count($args)) {
            throw new \LogicException(
                'phpc_argon2_hash() requires exactly six arguments in this compiler build'
            );
        }

        return JitArgon2Kernel::hash(
            $context,
            JitStringArg::lower($context, $args[0], 'phpc_argon2_hash() password'),
            JitLongArg::lower($context, $args[1], 'phpc_argon2_hash() type'),
            JitLongArg::lower($context, $args[2], 'phpc_argon2_hash() memory_cost'),
            JitLongArg::lower($context, $args[3], 'phpc_argon2_hash() time_cost'),
            JitLongArg::lower($context, $args[4], 'phpc_argon2_hash() threads'),
            JitStringArg::lower($context, $args[5], 'phpc_argon2_hash() salt')
        );
    }
}
