<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for connection_status() (issues #6161, #7234). */
final class JitConnectionStatus
{
    public static function invoke(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');

        // CLI: CONNECTION_NORMAL until web SAPI wires disconnect (#173).
        // JIT/AOT standalone still returns backing int; VM maps to ConnectionStatus (#7234).
        return $i64->constInt(VmConnection::NORMAL, false);
    }
}
