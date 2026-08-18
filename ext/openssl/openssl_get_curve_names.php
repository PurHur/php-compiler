<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openssl_get_curve_names() — built-in EC curve names (#6560 VM, JIT/AOT #32364 via HashTable bake).
 *
 * php-src: ext/openssl/openssl.c PHP_FUNCTION(openssl_get_curve_names) / EC_get_builtin_curves.
 */
final class openssl_get_curve_names extends Internal
{
    public function __construct()
    {
        parent::__construct('openssl_get_curve_names');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError(
                'openssl_get_curve_names() expects exactly 0 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmOpenssl::curveNames());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                'openssl_get_curve_names() expects exactly 0 arguments, '.$argc.' given'
            );
        }

        return JitOpensslMethods::curveNames($context);
    }
}
