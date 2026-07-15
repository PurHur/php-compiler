<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_pkcs7_decrypt() — S/MIME decrypt (php-src ext/openssl/openssl.c; #6804).
 */
final class openssl_pkcs7_decrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkcs7_decrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_pkcs7_decrypt() expects at least 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_pkcs7_decrypt', 0, 'input_filename');
        $output = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_pkcs7_decrypt', 1, 'output_filename');
        $keyArg = $argc >= 4 ? $frame->calledArgs[3] : $frame->calledArgs[2];

        $ok = VmOpenssl::pkcs7Decrypt(
            $input,
            $output,
            $frame->calledArgs[2],
            $keyArg,
            $frame
        );
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkcs7_decrypt() is not implemented for JIT in this compiler build (issue #6804)'
        );
    }
}
