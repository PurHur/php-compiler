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
 * __compiler_libcrypt() — libc crypt(3) for VmPasswordPure SSOT (VM + JIT/AOT, #9275).
 *
 * Not a user API; avoids AOT recursion through crypt() builtin → PasswordJitHelper.
 * php-src: ext/standard/crypt.c
 */
final class __compiler_libcrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('__compiler_libcrypt');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                '__compiler_libcrypt() requires exactly two arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $key = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], '__compiler_libcrypt', 0, 'key');
        $salt = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[1], '__compiler_libcrypt', 1, 'salt');
        if (!\function_exists('crypt')) {
            $frame->returnVar->null();

            return;
        }
        $result = \crypt($key, $salt);
        if (!\is_string($result) || '' === $result || '*' === $result[0]) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException(
                '__compiler_libcrypt() requires exactly two arguments in this compiler build'
            );
        }

        return JitLibcrypt::invoke(
            $context,
            JitStringArg::lower($context, $args[0], '__compiler_libcrypt() key'),
            JitStringArg::lower($context, $args[1], '__compiler_libcrypt() salt')
        );
    }
}
