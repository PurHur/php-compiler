<?php

declare(strict_types=1);

/**
 * Pending response headers reset hook for standalone JIT/AOT main (issue #311).
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

final class PendingHeaders
{
    public static function emitResetForStandaloneMain(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        $context->builder->call($context->lookupFunction('__phpc_pending_header_reset'));
    }

    /**
     * Emit CGI Status + queued header() lines once (issue #634).
     */
    public static function emitFlushForStandalone(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        if (null !== $context->headerPreFlushFunc) {
            $context->builder->call($context->headerPreFlushFunc);
        }
        $context->builder->call($context->lookupFunction('__phpc_response_headers_flush'));
    }
}
