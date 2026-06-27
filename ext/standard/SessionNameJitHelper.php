<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * session_name() validation for JIT/AOT (#12563, ext/session/session.c).
 *
 * SSOT: {@see VmSession::isRejectedSessionName}.
 */
final class SessionNameJitHelper
{
    public static function isRejected(string $name): bool
    {
        return VmSession::isRejectedSessionName($name);
    }

    public static function warningMessage(string $name): string
    {
        return VmSession::rejectedSessionNameMessage($name);
    }
}
