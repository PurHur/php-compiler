<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * filter_list() stub — names for supported validators (php-src ext/filter/filter.c; #5839).
 */
final class filter_list extends Internal
{
    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray(FilterConstants::supportedFilterNames()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFilterList::invoke($context);
    }
}
