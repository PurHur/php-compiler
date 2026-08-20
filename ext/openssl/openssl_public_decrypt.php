<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_public_decrypt() — asymmetric public-key decryption (php-src ext/openssl/openssl.c; #6666 VM, JIT/AOT #32761).
 */
final class openssl_public_decrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_public_decrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_public_decrypt() expects 3 or 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $data = VmOpenssl::coerceSignatureArg($frame->calledArgs[0], 'openssl_public_decrypt', 0, 'data');
        $keyPem = VmOpenssl::coercePkeyPem($frame->calledArgs[2], 'openssl_public_decrypt', 2, 'key');
        $padding = OpensslConstants::OPENSSL_PKCS1_PADDING;
        if (4 === $argc) {
            $padding = VmOpenssl::coercePaddingArg($frame->calledArgs[3], 'openssl_public_decrypt', 3, 'padding');
        }

        $decrypted = VmOpenssl::publicDecrypt($data, $keyPem, $padding, $frame);
        if (false === $decrypted) {
            $frame->returnVar->bool(false);

            return;
        }

        $decryptedOut = $frame->calledArgs[1]->resolveIndirect();
        $decryptedOut->string($decrypted);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_public_decrypt() expects 3 or 4 arguments, '.$argc.' given'
            );
        }

        return JitOpensslX509::publicDecrypt(
            $context,
            $args[0],
            $args[1],
            $args[2],
            $args[3] ?? null
        );
    }
}
