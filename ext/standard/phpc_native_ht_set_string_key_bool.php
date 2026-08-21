<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ParseStrNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** @internal native hashtable string key + bool value write (#33670). */
final class phpc_native_ht_set_string_key_bool extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_ht_set_string_key_bool');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_native_ht_set_string_key_bool() is JIT-only (#33670)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('phpc_native_ht_set_string_key_bool() expects 3 arguments');
        }
        ParseStrNativeOpsJit::setStringKeyBool($context, $args[0], $args[1], $args[2]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
