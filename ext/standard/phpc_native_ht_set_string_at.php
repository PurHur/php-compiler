<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ParseStrNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal parse_str native hashtable list index write (#13827). */
final class phpc_native_ht_set_string_at extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_ht_set_string_at');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_native_ht_set_string_at() is JIT-only (#13827)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('phpc_native_ht_set_string_at() expects 3 arguments');
        }
        ParseStrNativeOpsJit::setStringAt($context, $args[0], $args[1], $args[2]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
