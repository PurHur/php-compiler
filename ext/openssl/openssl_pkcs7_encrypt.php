<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_pkcs7_encrypt() — S/MIME encrypt (php-src ext/openssl/openssl.c; #6804).
 */
final class openssl_pkcs7_encrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkcs7_encrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 6) {
            throw new \ArgumentCountError(
                'openssl_pkcs7_encrypt() expects at least 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_pkcs7_encrypt', 0, 'input_filename');
        $output = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_pkcs7_encrypt', 1, 'output_filename');
        $flags = 0;
        if ($argc >= 5) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'openssl_pkcs7_encrypt', 5, 'flags');
        }
        $cipherId = OpensslConstants::OPENSSL_CIPHER_AES_128_CBC;
        if ($argc >= 6) {
            $cipherId = VmMath::parseIntBuiltinArgForFrame($frame, 5, 'openssl_pkcs7_encrypt', 6, 'cipher_algo');
        }

        $ok = VmOpenssl::pkcs7Encrypt(
            $input,
            $output,
            $frame->calledArgs[2],
            $frame->calledArgs[3],
            $flags,
            $cipherId,
            $frame
        );
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkcs7_encrypt() is not implemented for JIT in this compiler build (issue #6804)'
        );
    }
}
