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
 * openssl_encrypt() — symmetric EVP cipher (php-src ext/openssl/openssl.c; #18594).
 */
final class openssl_encrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_encrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 8) {
            throw new \ArgumentCountError(
                'openssl_encrypt() expects at least 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_encrypt', 0, 'data');
        $cipherAlgo = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_encrypt', 1, 'cipher_algo');
        $passphrase = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'openssl_encrypt', 2, 'passphrase');
        $options = 0;
        if ($argc >= 4) {
            $options = VmMath::parseIntBuiltinArgForFrame($frame, 3, 'openssl_encrypt', 4, 'options');
        }
        $iv = '';
        if ($argc >= 5) {
            $iv = VmString::coerceStringBuiltinArg($frame->calledArgs[4], 'openssl_encrypt', 4, 'iv');
        }

        $encrypted = VmOpenssl::encrypt($data, $cipherAlgo, $passphrase, $options, $iv, $frame);
        if (false === $encrypted) {
            $frame->returnVar->bool(false);

            return;
        }

        if (0 === ($options & OpensslConstants::OPENSSL_RAW_DATA)) {
            $encrypted = base64_encode($encrypted);
        }
        $frame->returnVar->string($encrypted);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_encrypt() is not implemented for JIT in this compiler build (issue #18594)'
        );
    }
}
