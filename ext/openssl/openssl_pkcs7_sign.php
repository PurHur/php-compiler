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
 * openssl_pkcs7_sign() — S/MIME sign (php-src ext/openssl/openssl.c; #6804).
 */
final class openssl_pkcs7_sign extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkcs7_sign');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 7) {
            throw new \ArgumentCountError(
                'openssl_pkcs7_sign() expects at least 5 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_pkcs7_sign', 0, 'input_filename');
        $output = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_pkcs7_sign', 1, 'output_filename');
        $flags = OpensslConstants::PKCS7_DETACHED;
        if ($argc >= 6) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 5, 'openssl_pkcs7_sign', 6, 'flags');
        }

        $ok = VmOpenssl::pkcs7Sign(
            $input,
            $output,
            $frame->calledArgs[2],
            $frame->calledArgs[3],
            $frame->calledArgs[4],
            $flags,
            $frame
        );
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkcs7_sign() is not implemented for JIT in this compiler build (issue #6804)'
        );
    }
}
