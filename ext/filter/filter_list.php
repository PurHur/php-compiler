<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * filter_list() stub — names for supported validators (php-src ext/filter/filter.c; #5839).
 */
final class filter_list extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 0 (#30675; ext/filter/filter.c).
        $this->requireExactArgCount($frame, 'filter_list', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray(FilterConstants::supportedFilterNames()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'filter_list', 0)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitFilterList::invoke($context);
    }
}
