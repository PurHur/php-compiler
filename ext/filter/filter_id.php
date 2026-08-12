<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
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
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('filter_id() requires exactly one argument');
        }
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
        if (\count($args) < 1) {
            throw new \LogicException('filter_id() requires exactly one argument');
        }

        return JitFilterId::invoke($context, $args[0]);
    }
}
