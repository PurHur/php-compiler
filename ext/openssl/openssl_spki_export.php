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
 * openssl_spki_export() — PEM public key from Netscape SPKAC (php-src ext/openssl/openssl.c; #6423).
 */
final class openssl_spki_export extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_spki_export');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_spki_export() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $spkac = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_spki_export', 0, 'spkac');
        $pem = VmOpenssl::spkiExport($spkac, $frame);
        if (false === $pem) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->string($pem);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_spki_export() is not implemented for JIT in this compiler build (issue #6423)'
        );
    }
}
