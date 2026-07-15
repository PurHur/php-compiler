<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_csr_get_subject() — DN from CSR (php-src ext/openssl/xp.c; #6421).
 */
final class openssl_csr_get_subject extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_csr_get_subject');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'openssl_csr_get_subject() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $shortnames = true;
        if (2 === $argc) {
            $shortnames = VmOpenssl::coerceBoolArg(
                $frame->calledArgs[1],
                'openssl_csr_get_subject',
                1,
                'short_names'
            );
        }

        $subject = VmOpenssl::csrGetSubject($frame->calledArgs[0], $shortnames, $frame);
        if (false === $subject) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->copyFrom($subject);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_csr_get_subject() is not implemented for JIT in this compiler build (issue #6421)'
        );
    }
}
