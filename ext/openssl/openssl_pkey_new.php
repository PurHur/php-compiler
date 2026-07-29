<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_pkey_new() — generate asymmetric key (php-src ext/openssl/openssl.c; #6295, #22335).
 *
 * Reflection / named-arg param is Zend stub `options` (not InternalArgInfo `configargs`; #24491).
 */
final class openssl_pkey_new extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_pkey_new');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'openssl_pkey_new() expects at most 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        $config = 1 === \count($frame->calledArgs) ? $frame->calledArgs[0] : null;
        $key = VmOpenssl::pkeyNew($config, $frame->vmContext, $frame);
        if (false === $key) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object($key->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'openssl_pkey_new() is not implemented for JIT in this compiler build (issue #6295/#22335)'
        );
    }
}
