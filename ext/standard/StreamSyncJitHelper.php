<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;

/**
 * fsync()/fdatasync() semantics for compiled JIT/AOT modules (#9815, php-in-PHP).
 *
 * VM SSOT: {@see VmFs::fsync()} / {@see VmFs::fdatasync()}
 * php-src: ext/standard/file.c — PHP_FUNCTION(fsync), PHP_FUNCTION(fdatasync)
 */
final class StreamSyncJitHelper
{
    public static function isSyncSupported(int $handle): int
    {
        return VmStreamSync::isSupported($handle) ? 1 : 0;
    }

    public static function warnUnsyncable(int $dataOnly): void
    {
        $message = 0 !== $dataOnly
            ? VmStreamSync::FDATASYNC_UNSYNCABLE_WARNING
            : VmStreamSync::FSYNC_UNSYNCABLE_WARNING;
        ErrorLastJitHelper::record(ErrorReporter::E_WARNING, $message, '', 0);
        if (ErrorSilenceJitHelper::isErrorLevelEnabled(ErrorReporter::E_WARNING)) {
            TriggerErrorJitHelper::stderrPrintCliError(ErrorReporter::E_WARNING, $message, '', 0);
        }
    }

    public static function syncFileno(int $fd, int $dataOnly): int
    {
        return VmPhpFdStream::syncFileno($fd, 0 !== $dataOnly) ? 1 : 0;
    }
}
