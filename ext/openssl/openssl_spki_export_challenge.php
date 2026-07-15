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
 * openssl_spki_export_challenge() — challenge from Netscape SPKAC (php-src ext/openssl/openssl.c; #6423).
 */
final class openssl_spki_export_challenge extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_spki_export_challenge');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_spki_export_challenge() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $spkac = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_spki_export_challenge', 0, 'spkac');
        $challenge = VmOpenssl::spkiExportChallenge($spkac, $frame);
        if (false === $challenge) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->string($challenge);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_spki_export_challenge() is not implemented for JIT in this compiler build (issue #6423)'
        );
    }
}
