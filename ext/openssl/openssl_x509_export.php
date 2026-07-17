<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_x509_export() — export certificate PEM (php-src ext/openssl/openssl.c; #20273).
 */
final class openssl_x509_export extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_x509_export');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_x509_export() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $noText = true;
        if (3 === $argc) {
            $noText = VmOpenssl::coerceBoolArg(
                $frame->calledArgs[2],
                'openssl_x509_export',
                2,
                'no_text'
            );
        }

        $exported = VmOpenssl::x509ExportPem($frame->calledArgs[0], $noText, $frame);
        if (false === $exported) {
            $frame->returnVar->bool(false);

            return;
        }

        $outVar = $frame->calledArgs[1]->resolveIndirect();
        $outVar->string($exported);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_x509_export() is not implemented for JIT in this compiler build (issue #20273)'
        );
    }
}
