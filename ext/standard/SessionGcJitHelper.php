<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * session_gc() file purge for compiled JIT/AOT modules (#9411, php-in-PHP).
 *
 * SSOT: {@see VmSession::gcExpiredFiles}
 * php-src: ext/session/mod_files.c — ps_files_cleanup_dir
 */
final class SessionGcJitHelper
{
    /**
     * Scan save path and unlink expired sess_* files.
     *
     * @return int -1 on failure (LLVM i64 ABI), else deleted count
     */
    public static function gcExpiredFilesAsInt(): int
    {
        $result = VmSession::gcExpiredFiles();

        return false === $result ? -1 : $result;
    }
}
