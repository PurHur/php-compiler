<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\WeakRefNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal weakref clear — null weakref slot value box (#15968). */
final class phpc_weakref_null_slot extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_weakref_null_slot');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_weakref_null_slot() is JIT-only (#15968)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_weakref_null_slot() expects 1 argument');
        }
        WeakRefNativeOpsJit::nullSlot($context, $args[0]);

        return $context->constantFromInteger(0, 'int32');
    }
}
