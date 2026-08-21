<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ParseStrNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal NestedJIT hashtable packed null write (#33640). */
final class phpc_native_ht_set_null_at extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_ht_set_null_at');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_native_ht_set_null_at() is JIT-only (#33640)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_native_ht_set_null_at() expects 2 arguments');
        }
        ParseStrNativeOpsJit::setNullAt($context, $args[0], $args[1]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
