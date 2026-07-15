<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_csr_export() — export CSR PEM (php-src ext/openssl/xp.c; #6421).
 */
final class openssl_csr_export extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_csr_export');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'openssl_csr_export() expects 2 or 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $exported = VmOpenssl::csrExportPem($frame->calledArgs[0], $frame);
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
            'openssl_csr_export() is not implemented for JIT in this compiler build (issue #6421)'
        );
    }
}
