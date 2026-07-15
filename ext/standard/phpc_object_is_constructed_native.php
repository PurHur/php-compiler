<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\GcCollectCyclesNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal embed GC — test object constructed flag before destructor (#18660). */
final class phpc_object_is_constructed_native extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_object_is_constructed_native');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_object_is_constructed_native() is JIT-only (#18660)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_object_is_constructed_native() expects 1 argument');
        }
        $isConstructed = GcCollectCyclesNativeOpsJit::objectIsConstructed($context, $args[0]);

        return $context->builder->zext($isConstructed, $context->getTypeFromString('int32'));
    }
}
