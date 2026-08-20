<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\session\SessionConstants;
use PHPCompiler\JIT\Builtin\SessionStorageGlobals;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for session_status() (issues #7321). */
final class JitSessionStatus
{
    public static function invoke(Context $context): Value
    {
        SessionStorageGlobals::ensureGlobals($context);

        // activeGlobal is i8 (#5750 / SessionStorageGlobals::ensureGlobals); icmp must match
        // peer SessionId / JitSessionLifecycleKernel — not i64 (#32999).
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $activeNonZero = $context->builder->icmp(
            Builder::INT_NE,
            $active,
            $i8->constInt(0, false)
        );

        return $context->builder->select(
            $activeNonZero,
            $i64->constInt(SessionConstants::PHP_SESSION_ACTIVE, false),
            $i64->constInt(SessionConstants::PHP_SESSION_NONE, false)
        );
    }
}
