<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_socket_get_name() — socket address formatting (issue #12223, #12445).
 *
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_socket_get_name)
 */
final class VmStreamSocketGetName
{
    public static function getName(int $handle, bool $wantPeer): string|false
    {
        return VmStreamSocketGetNamePure::getName($handle, $wantPeer);
    }
}
