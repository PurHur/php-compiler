<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_get_cipher_methods() — registered cipher names (#6228 VM, #21103 JIT/AOT NestedJIT; ext/openssl/openssl.c).
 */
final class openssl_get_cipher_methods extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_get_cipher_methods');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'openssl_get_cipher_methods() expects at most 1 argument, '.$argc.' given'
            );
        }
        $aliases = false;
        if (1 === $argc) {
            $aliases = VmOpenssl::coerceBoolArg(
                $frame->calledArgs[0],
                'openssl_get_cipher_methods',
                0,
                'aliases'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmOpenssl::cipherMethods($aliases));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'openssl_get_cipher_methods() expects at most 1 argument, '.$argc.' given'
            );
        }

        return JitOpensslMethods::cipherMethods($context, $args[0] ?? null);
    }
}
