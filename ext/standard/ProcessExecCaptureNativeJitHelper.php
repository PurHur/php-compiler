<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * exec()/passthru()/system() capture for AOT nested JIT (#10492).
 *
 * Uses phpc_native_ht_* + {@see VmShellExecNative} — no VM HashTable::add / VmFs loops.
 * php-src: ext/standard/exec.c
 */
final class ProcessExecCaptureNativeJitHelper
{
    /** @return int native __hashtable__* as i64; 0 when capture fails */
    public static function processExecCaptureArgv(?string $command): int
    {
        if (null === $command || '' === $command) {
            return 0;
        }

        $output = VmShellExecNative::shellExec($command);
        if (false === $output) {
            return 0;
        }

        $linesPtr = (int) phpc_native_ht_alloc();
        phpc_native_ht_set_string_at($linesPtr, 0, rtrim($output, "\r\n"));

        $resultPtr = (int) phpc_native_ht_alloc();
        phpc_native_ht_set_string_key_ht($resultPtr, 'lines', $linesPtr);
        phpc_native_ht_set_string_key_long($resultPtr, 'status', 0);

        return $resultPtr;
    }
}
