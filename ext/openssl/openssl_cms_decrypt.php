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
 * openssl_cms_decrypt() — CMS/S/MIME decrypt (php-src ext/openssl/openssl.c; #6592).
 */
final class openssl_cms_decrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_cms_decrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \ArgumentCountError(
                'openssl_cms_decrypt() expects at least 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_cms_decrypt', 0, 'input_filename');
        $output = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_cms_decrypt', 1, 'output_filename');
        $encoding = OpensslConstants::OPENSSL_ENCODING_SMIME;
        if ($argc >= 5) {
            $encoding = VmMath::parseIntBuiltinArgForFrame($frame, 4, 'openssl_cms_decrypt', 5, 'encoding');
        }

        $keyArg = $argc >= 4 ? $frame->calledArgs[3] : $frame->calledArgs[2];
        $ok = VmOpenssl::cmsDecrypt(
            $input,
            $output,
            $frame->calledArgs[2],
            $keyArg,
            $encoding,
            $frame
        );
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_cms_decrypt() is not implemented for JIT in this compiler build (issue #6592)'
        );
    }
}
