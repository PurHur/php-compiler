<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for trigger_error() (issue #1221).
 */
final class JitTriggerError
{
    public static function emit(Context $context, Value $msgPtr, Value $typeI32): void
    {
        $map = $context->structFieldMap['__string__'];
        $msgLen = $context->builder->load(
            $context->builder->structGep($msgPtr, $map['length'])
        );
        $msgData = $context->builder->structGep($msgPtr, $map['value']);
        $context->builder->call(
            $context->lookupFunction('__phpc_trigger_error_cstr'),
            $msgData,
            $msgLen,
            $typeI32
        );
    }
}
