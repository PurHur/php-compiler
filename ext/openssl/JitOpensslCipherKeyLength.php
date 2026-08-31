<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPLLVM\Value;

/** LLVM lowering for openssl_cipher_key_length() (#6522, ext/openssl/openssl.c). */
final class JitOpensslCipherKeyLength
{
    private const UNKNOWN_CIPHER_WARNING = 'openssl_cipher_key_length(): Unknown cipher algorithm';

    public static function invoke(Context $context, JITVariable $cipherArg): Value
    {
        $literal = $cipherArg->compileTimeString ?? null;
        if (null === $literal) {
            throw new \LogicException(
                'openssl_cipher_key_length() cipher_algo must be a compile-time string literal '
                .'in this compiler build (issue #6522)'
            );
        }

        $length = OpensslCipherRegistry::cipherKeyLength($literal);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (false === $length) {
            JitBuiltinWarning::emit($context, self::UNKNOWN_CIPHER_WARNING);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }
        JitValueBox::writeLong($context, $slot, $context->getTypeFromString('int64')->constInt($length, false));

        return $ptr;
    }
}
