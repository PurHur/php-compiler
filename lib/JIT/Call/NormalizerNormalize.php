<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\intl\JitNormalizerNormalize;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Normalizer::normalize() — JIT/AOT NFC/NFD via NormalizerNormalizeJitHelper (#28654).
 *
 * php-src: ext/intl/normalizer/normalizer_normalize.c — zim_Normalizer_normalize
 */
final class NormalizerNormalize implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitNormalizerNormalize::invokeMethod($context, ...$args);
    }
}
