<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\VmActiveContextJitHelper;

/**
 * AOT session_start($options) NestedJIT helper — not in prelinked helper-runtime (#33945).
 *
 * SSOT: {@see SessionStartOptions}, {@see VmSession}.
 * php-src: ext/session/session.c — PHP_FUNCTION(session_start)
 */
final class SessionStartOptionsAotJitHelper
{
    public static function applyOptions(HashTable $options): void
    {
        $ctx = VmActiveContextJitHelper::resolve();
        SessionStartOptions::applyJit($ctx, $options);
    }
}
