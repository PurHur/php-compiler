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
 * @internal libc crypt(3) kernel for LibcryptJitHelper (#9275, #26773).
 *
 * VM: host crypt(); JIT/AOT NestedJIT leaf: {@see JitLibcryptKernel}.
 * php-src: ext/standard/crypt.c — PHP_FN(crypt)
 */
final class phpc_libcrypt_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_libcrypt_kernel');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException(
                'phpc_libcrypt_kernel() requires exactly two arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $key = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'phpc_libcrypt_kernel', 0, 'key');
        $salt = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[1], 'phpc_libcrypt_kernel', 1, 'salt');
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
                'phpc_libcrypt_kernel() requires exactly two arguments in this compiler build'
            );
        }

        return JitLibcryptKernel::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'phpc_libcrypt_kernel() key'),
            JitStringArg::lower($context, $args[1], 'phpc_libcrypt_kernel() salt')
        );
    }
}
