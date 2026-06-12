<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for openssl_cipher_iv_length() (#7331 phase 2, ext/openssl/openssl.c). */
final class JitOpensslCipherIvLength
{
    public static function invoke(Context $context, JITVariable $cipherArg): Value
    {
        $literal = $cipherArg->compileTimeString ?? null;
        if (null === $literal) {
            throw new \LogicException(
                'openssl_cipher_iv_length() cipher_algo must be a compile-time string literal '
                .'in this compiler build (issue #7331)'
            );
        }

        $length = OpensslCipherRegistry::cipherIvLength($literal);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $length) {
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }
        JitValueBox::writeLong($context, $slot, $context->getTypeFromString('int64')->constInt($length, false));

        return $ptr;
    }
}
