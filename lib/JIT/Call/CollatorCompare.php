<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\intl\JitCollatorCompare;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Collator::compare() — JIT/AOT UTF-8 compare via CollatorCompareJitHelper (#28649).
 *
 * php-src: ext/intl/collator/collator_compare.c — zim_Collator_compare
 */
final class CollatorCompare implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitCollatorCompare::invokeMethod($context, ...$args);
    }
}
