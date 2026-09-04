<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Normalizer::normalize() — JIT/AOT NFC/NFD via NormalizerNormalizeJitHelper (#28654).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\intl} (#36204). php-src: ext/intl/normalizer/normalizer_normalize.c — zim_Normalizer_normalize
 */
final class NormalizerNormalize implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireIntl()->normalizerNormalize($context, ...$args);
    }
}
