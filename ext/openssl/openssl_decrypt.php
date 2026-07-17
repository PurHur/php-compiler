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
 * openssl_decrypt() — symmetric EVP cipher (php-src ext/openssl/openssl.c; #18594).
 */
final class openssl_decrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_decrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 8) {
            throw new \ArgumentCountError(
                'openssl_decrypt() expects at least 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        // Z_PARAM_STR $data — null TypeError on 8.4 forward profile (#20263, re-#19038, ext/openssl/openssl.c);
        // 8.2 still coerces+deprecates like openssl_digest (#20207).
        $data = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'openssl_decrypt', 0, 'data');
        $cipherAlgo = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_decrypt', 1, 'cipher_algo');
        $passphrase = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'openssl_decrypt', 2, 'passphrase');
        $options = 0;
        if ($argc >= 4) {
            $options = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'openssl_decrypt', 4, 'options');
        }
        $iv = '';
        if ($argc >= 5) {
            $iv = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'openssl_decrypt', 4, 'iv');
        }

        $payload = $data;
        if (0 === ($options & OpensslConstants::OPENSSL_RAW_DATA)) {
            $decoded = base64_decode($data, true);
            if (false === $decoded) {
                VmOpenssl::userWarningForFrame('openssl_decrypt(): Input is not valid base64', $frame);
                $frame->returnVar->bool(false);

                return;
            }
            $payload = $decoded;
        }

        $plain = VmOpenssl::decrypt($payload, $cipherAlgo, $passphrase, $options, $iv, $frame);
        if (false === $plain) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($plain);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_decrypt() is not implemented for JIT in this compiler build (issue #18594)'
        );
    }
}
