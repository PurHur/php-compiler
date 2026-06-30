<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ParseStrNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal parse_str native hashtable alloc — returns ptr as i64 (#13827). */
final class phpc_native_ht_alloc extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_ht_alloc');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_native_ht_alloc() is JIT-only (#13827)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return ParseStrNativeOpsJit::alloc($context);
    }
}
