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
 * openssl_private_encrypt() — asymmetric private-key encryption (php-src ext/openssl/xp.c; #6666).
 */
final class openssl_private_encrypt extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_private_encrypt');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError(
                'openssl_private_encrypt() expects 3 or 4 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_private_encrypt', 0, 'data');
        $keyPem = VmOpenssl::coercePkeyPem($frame->calledArgs[2], 'openssl_private_encrypt', 2, 'key');
        $padding = OpensslConstants::OPENSSL_PKCS1_PADDING;
        if (4 === $argc) {
            $padding = VmOpenssl::coercePaddingArg($frame->calledArgs[3], 'openssl_private_encrypt', 3, 'padding');
        }

        $encrypted = VmOpenssl::privateEncrypt($data, $keyPem, $padding, $frame);
        if (false === $encrypted) {
            $frame->returnVar->bool(false);

            return;
        }

        $encryptedOut = $frame->calledArgs[1]->resolveIndirect();
        $encryptedOut->string($encrypted);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_private_encrypt() is not implemented for JIT in this compiler build (issue #6666)'
        );
    }
}
