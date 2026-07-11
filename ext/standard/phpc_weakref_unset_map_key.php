<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\WeakRefNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal weakref clear — unset weakmap key from target hashtable (#15968). */
final class phpc_weakref_unset_map_key extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_weakref_unset_map_key');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_weakref_unset_map_key() is JIT-only (#15968)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_weakref_unset_map_key() expects 2 arguments');
        }
        WeakRefNativeOpsJit::unsetMapKey($context, $args[0], $args[1]);

        return $context->constantFromInteger(0, 'int32');
    }
}
