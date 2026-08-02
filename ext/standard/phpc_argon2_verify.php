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
 * @internal libargon2 verify kernel for PasswordJitHelper NestedJIT (#26773).
 *
 * VM: {@see VmPasswordNative}; JIT/AOT NestedJIT leaf: {@see JitArgon2Kernel}.
 * php-src: ext/standard/password.c — argon2_verify
 */
final class phpc_argon2_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_argon2_verify');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                'phpc_argon2_verify() requires exactly three arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $password = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'phpc_argon2_verify', 0, 'password');
        $hash = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[1], 'phpc_argon2_verify', 1, 'hash');
        $frame->returnVar->int(VmPasswordNative::passwordVerify($password, $hash) ? 1 : 0);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException(
                'phpc_argon2_verify() requires exactly three arguments in this compiler build'
            );
        }

        return JitArgon2Kernel::verify(
            $context,
            JitStringArg::lower($context, $args[0], 'phpc_argon2_verify() password'),
            JitStringArg::lower($context, $args[1], 'phpc_argon2_verify() hash'),
            JitLongArg::lower($context, $args[2], 'phpc_argon2_verify() type')
        );
    }
}
