<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Collator::compare() — JIT/AOT UTF-8 compare via CollatorCompareJitHelper (#28649).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\intl} (#36204). php-src: ext/intl/collator/collator_compare.c — zim_Collator_compare
 */
final class CollatorCompare implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireIntl()->collatorCompare($context, ...$args);
    }
}
