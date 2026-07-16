<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\VM\Context;

/** Register ext/sockets builtin classes (php-src ext/sockets/sockets.c; #6544). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmSocket::registerClass($ctx);
        VmAddressInfo::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
