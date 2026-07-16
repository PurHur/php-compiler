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
 * openssl_cms_encrypt() — CMS/S/MIME encrypt (php-src ext/openssl/openssl.c; #6592).
 */
final class openssl_cms_encrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_cms_encrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 7) {
            throw new \ArgumentCountError(
                'openssl_cms_encrypt() expects at least 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_cms_encrypt', 0, 'input_filename');
        $output = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_cms_encrypt', 1, 'output_filename');
        $flags = 0;
        if ($argc >= 5) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'openssl_cms_encrypt', 5, 'flags');
        }
        $encoding = OpensslConstants::OPENSSL_ENCODING_SMIME;
        if ($argc >= 6) {
            $encoding = VmMath::parseIntBuiltinArgForFrame($frame, 5, 'openssl_cms_encrypt', 6, 'encoding');
        }
        $cipherId = OpensslConstants::OPENSSL_CIPHER_AES_128_CBC;
        if ($argc >= 7) {
            $cipherId = VmMath::parseIntBuiltinArgForFrame($frame, 6, 'openssl_cms_encrypt', 7, 'cipher_algo');
        }

        $ok = VmOpenssl::cmsEncrypt(
            $input,
            $output,
            $frame->calledArgs[2],
            $frame->calledArgs[3],
            $flags,
            $encoding,
            $cipherId,
            $frame
        );
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_cms_encrypt() is not implemented for JIT in this compiler build (issue #6592)'
        );
    }
}
