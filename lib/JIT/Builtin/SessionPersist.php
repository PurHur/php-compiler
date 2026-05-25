<?php

declare(strict_types=1);

/**
 * Persist $_SESSION to disk before standalone AOT exits (issues #1938, #1891).
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

final class SessionPersist
{
    public static function emitShutdownPersistForStandalone(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        $context->builder->call($context->lookupFunction('__phpc_session_shutdown_persist'));
    }
}
