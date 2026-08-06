<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * getrusage() for compiled JIT/AOT modules (#9184, #27551, php-in-PHP).
 *
 * NestedJIT must not return {@see HashTable} under thin AOT — Variable+HashTable::add
 * miscompiles to setStringKeyObject and the pointer-cast HT segfaults on isset/count
 * (#27551; peer gc_status #26943 / sys_getloadavg #27294). Expose scalars via
 * {@see VmGetrusagePure::scalarAt} (no NestedJIT associative-array reads); the LLVM
 * bridge materializes a real `__hashtable__*`.
 *
 * SSOT: {@see VmGetrusageNative} / {@see VmGetrusagePure};
 * host {@see resolve()} still builds via {@see VmProcess::getrusage}.
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getrusage)
 */
final class GetrusageJitHelper
{
    /**
     * Stable key order matching {@see VmGetrusagePure} / php-src getrusage() shape.
     *
     * @var list<string>
     */
    public const KEYS = [
        'ru_oublock',
        'ru_inblock',
        'ru_msgsnd',
        'ru_msgrcv',
        'ru_maxrss',
        'ru_ixrss',
        'ru_idrss',
        'ru_minflt',
        'ru_majflt',
        'ru_nsignals',
        'ru_nvcsw',
        'ru_nivcsw',
        'ru_nswap',
        'ru_utime.tv_usec',
        'ru_utime.tv_sec',
        'ru_stime.tv_usec',
        'ru_stime.tv_sec',
    ];

    /** Host / unit tests — build a real VM HashTable (not NestedJIT under thin AOT). */
    public static function resolve(int $who): ?HashTable
    {
        $usage = VmProcess::getrusage($who);

        return false === $usage ? null : $usage;
    }

    /**
     * NestedJIT-safe availability — avoid bool/nullable NestedJIT edges; use int 0/1.
     * Uses {@see VmGetrusageNative::getrusage} so /proc parsing is pulled into the helper graph.
     */
    public static function resolveOk(int $who): int
    {
        return false === VmGetrusageNative::getrusage($who) ? 0 : 1;
    }

    /**
     * NestedJIT-safe scalar fetch — packed indices, no associative array (#27551).
     *
     * Index order matches {@see KEYS} / StringGetrusageRuntime bridge.
     */
    public static function valueAt(int $who, int $index): int
    {
        return VmGetrusagePure::scalarAt(VmGetrusageNative::normalizeWho($who), $index);
    }
}
