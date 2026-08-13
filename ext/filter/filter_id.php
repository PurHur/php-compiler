<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * filter_id() stub — map filter name to FILTER_* id (php-src ext/filter/filter.c; #5839).
 */
final class filter_id extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: exactly 1 (#30594; ext/filter/filter.c).
        $this->requireExactArgCount($frame, 'filter_id', 1);
        // php-src filter_id(string $name) — null rejected under strict_types (#30309).
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, 0, 'filter_id', 'name');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $name = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'filter_id',
            0,
            'name'
        );
        $id = FilterConstants::idForName($name);
        if (null === $id) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($id);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'filter_id', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitFilterId::invoke($context, $args[0]);
    }
}
