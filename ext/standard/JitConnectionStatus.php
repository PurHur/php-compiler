<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/** LLVM lowering for connection_status() (issue #6161). */
final class JitConnectionStatus
{
    public static function invoke(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');

        // CLI: CONNECTION_NORMAL until web SAPI wires disconnect (#173).
        return $i64->constInt(VmConnection::NORMAL, false);
    }
}
