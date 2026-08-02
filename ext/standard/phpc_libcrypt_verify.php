<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal bcrypt verify via crypt(3)+strcmp for PasswordJitHelper NestedJIT (#26773).
 *
 * Avoids NestedJIT string `===` which mis-lowers to __value__writeLong(i32).
 * php-src: ext/standard/password.c — php_password_verify / crypt
 */
final class phpc_libcrypt_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_libcrypt_verify');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                'phpc_libcrypt_verify() requires exactly two arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $password = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'phpc_libcrypt_verify', 0, 'password');
        $hash = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[1], 'phpc_libcrypt_verify', 1, 'hash');
        if (!\function_exists('crypt')) {
            $frame->returnVar->int(0);

            return;
        }
        $computed = \crypt($password, $hash);
        $frame->returnVar->int(
            (\is_string($computed) && '' !== $computed && '*' !== $computed[0] && $computed === $hash) ? 1 : 0
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException(
                'phpc_libcrypt_verify() requires exactly two arguments in this compiler build'
            );
        }

        return JitLibcryptKernel::verifyMatch(
            $context,
            JitStringArg::lower($context, $args[0], 'phpc_libcrypt_verify() password'),
            JitStringArg::lower($context, $args[1], 'phpc_libcrypt_verify() hash')
        );
    }
}
