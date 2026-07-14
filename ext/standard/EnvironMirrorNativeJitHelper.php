<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Process environ mirror for compiled JIT/AOT embed modules (#18984, php-in-PHP).
 *
 * User-script standalone refresh uses {@see \PHPCompiler\JIT\Builtin\EnvironMirrorRuntimeUserScriptCstr}
 * during init (#15417). SSOT: {@see \PHPCompiler\Web\Superglobals::applyProcessEnvironMirror()}
 * php-src: sapi/cli/php_cli.c — copy environ into $_SERVER on CLI startup
 */
final class EnvironMirrorNativeJitHelper
{
    public static function mirrorProcessEnvironNative(int $destPtr): void
    {
        if ($destPtr <= 0) {
            return;
        }
        foreach (VmEnvEnvironNative::enumerate() as $key => $value) {
            if (!\is_string($key) || '' === $key || !\is_string($value)) {
                continue;
            }
            phpc_native_ht_set_string_key($destPtr, $key, $value);
        }
    }
}
