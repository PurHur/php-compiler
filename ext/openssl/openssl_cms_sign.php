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
 * openssl_cms_sign() — CMS/S/MIME sign (php-src ext/openssl/openssl.c; #6592).
 */
final class openssl_cms_sign extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_cms_sign');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 8) {
            throw new \ArgumentCountError(
                'openssl_cms_sign() expects at least 5 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $input = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_cms_sign', 0, 'input_filename');
        $output = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'openssl_cms_sign', 1, 'output_filename');
        $flags = 0;
        if ($argc >= 6) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 5, 'openssl_cms_sign', 6, 'flags');
        }
        $encoding = OpensslConstants::OPENSSL_ENCODING_SMIME;
        if ($argc >= 7) {
            $encoding = VmMath::parseIntBuiltinArgForFrame($frame, 6, 'openssl_cms_sign', 7, 'encoding');
        }

        $ok = VmOpenssl::cmsSign(
            $input,
            $output,
            $frame->calledArgs[2],
            $frame->calledArgs[3],
            $frame->calledArgs[4],
            $flags,
            $encoding,
            $frame
        );
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_cms_sign() is not implemented for JIT in this compiler build (issue #6592)'
        );
    }
}
