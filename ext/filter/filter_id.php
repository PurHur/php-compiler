<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
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
        if (null === $frame->returnVar) {
            return;
        }
        $nameVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \LogicException('filter_id() filter name must be a string');
        }
        $id = FilterConstants::idForName($nameVar->toString());
        if (null === $id) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($id);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('filter_id() is not JIT-lowered in this compiler build');
    }
}
