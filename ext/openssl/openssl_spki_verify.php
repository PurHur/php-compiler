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
 * openssl_spki_verify() — Netscape SPKAC verification (php-src ext/openssl/openssl.c; #8690 VM, JIT/AOT #32776).
 */
final class openssl_spki_verify extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_spki_verify');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'openssl_spki_verify() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $spkac = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'openssl_spki_verify', 0, 'spkac');
        $frame->returnVar->bool(VmOpenssl::spkiVerify($spkac, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'openssl_spki_verify() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        return JitOpensslX509::spkiVerify($context, $args[0]);
    }
}
