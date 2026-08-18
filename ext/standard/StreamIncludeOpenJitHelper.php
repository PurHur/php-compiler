<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Script/include stream open gate for compiled JIT/AOT modules (#32104).
 *
 * VM SSOT: {@see VmStreamIncludeOpenPolicy}. php-src: main/streams/streams.c STREAM_OPEN_FOR_INCLUDE.
 */
final class StreamIncludeOpenJitHelper
{
    /**
     * When blocked, emits Zend-shaped warnings and returns true.
     */
    public static function warnIfBlocked(string $path, string $function, bool $forHighlight): bool
    {
        if (!VmStreamIncludeOpenPolicy::blockedForScriptOpen($path, null)) {
            return false;
        }
        VmStreamIncludeOpenPolicy::warnScriptOpenBlockedStandalone($function, $path, $forHighlight);

        return true;
    }
}
