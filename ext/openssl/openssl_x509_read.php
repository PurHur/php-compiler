<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_x509_read() — parse PEM into OpenSSLCertificate (php-src ext/openssl/xp.c; #7268, #6274).
 */
final class openssl_x509_read extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_x509_read');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_x509_read() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(
            VmOpensslObjects::readCertificate($frame->vmContext, $frame->calledArgs[0])
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_x509_read() is not implemented for JIT in this compiler build (issue #7268)'
        );
    }
}
