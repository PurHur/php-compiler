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
 * openssl_x509_fingerprint() — X.509 DER digest fingerprint (php-src ext/openssl/openssl.c; #6524 VM, JIT/AOT #32512).
 */
final class openssl_x509_fingerprint extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_x509_fingerprint');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_x509_fingerprint() expects at least 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $hashAlgo = 'sha1';
        if ($argc >= 2) {
            $hashAlgo = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'openssl_x509_fingerprint',
                1,
                'hash_algo'
            );
        }
        $rawOutput = false;
        if (3 === $argc) {
            $rawOutput = VmOpenssl::coerceBoolArg(
                $frame->calledArgs[2],
                'openssl_x509_fingerprint',
                2,
                'raw_output'
            );
        }

        $frame->returnVar->copyFrom(
            VmOpensslObjects::fingerprintCertificate(
                $frame->vmContext,
                $frame->calledArgs[0],
                $hashAlgo,
                $rawOutput,
                $frame
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_x509_fingerprint() expects at least 1 argument, '.$argc.' given'
            );
        }

        return JitOpensslX509::fingerprint($context, $args[0], $args[1] ?? null, $args[2] ?? null);
    }
}
