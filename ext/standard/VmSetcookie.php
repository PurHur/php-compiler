<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Web\ResponseContext;

/**
 * setcookie() / setrawcookie() header emission with headers-sent guard (ext/standard/head.c, #10865).
 */
final class VmSetcookie
{
    public static function emit(Frame $frame, string $function, string $headerLine): bool
    {
        if (VmSapiHeaderGuard::headersAlreadySent($frame)) {
            VmSapiHeaderGuard::warnHeadersAlreadySent($frame);

            return false;
        }
        ResponseContext::addHeader($headerLine, false);

        return true;
    }
}
