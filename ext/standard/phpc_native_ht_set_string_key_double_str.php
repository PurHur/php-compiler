<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ParseStrNativeOpsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal NestedJIT-safe string-key float write from serialize `d:` digit text (#33670).
 *
 * Third arg is the wire digits (e.g. `1.5` / `1.5E+2`); LLVM uses strtod — no NestedJIT float local.
 */
final class phpc_native_ht_set_string_key_double_str extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_native_ht_set_string_key_double_str');
    }

    public function execute(Frame $frame): void
    {
        throw new \LogicException('phpc_native_ht_set_string_key_double_str() is JIT-only (#33670)');
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('phpc_native_ht_set_string_key_double_str() expects 3 arguments');
        }
        ParseStrNativeOpsJit::setStringKeyDoubleFromString($context, $args[0], $args[1], $args[2]);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
