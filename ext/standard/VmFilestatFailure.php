<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\ErrorReporter;

/**
 * E_WARNING when path stat/lstat fails for filestat builtins (php-src ext/standard/filestat.c; #10548, #10547).
 */
final class VmFilestatFailure
{
    public static function warnPathStatFailed(Frame $frame, string $function, string $path, bool $lstat): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $op = $lstat ? 'Lstat' : 'stat';
        $message = \sprintf('%s(): %s failed for %s', $function, $op, $path);
        self::triggerWarning($frame, $message);
    }

    public static function warnChmodFailed(Frame $frame, string $path): void
    {
        self::triggerWarningWithHandlerFirst($frame, 'chmod(): No such file or directory');
    }

    public static function warnUnlinkFailed(Frame $frame, string $path): void
    {
        $message = VmFsPhpWrapper::isPhpWrapperPath($path)
            ? VmFsPhpWrapper::unlinkWarningMessage()
            : \sprintf('unlink(%s): No such file or directory', $path);
        self::triggerWarning($frame, $message);
    }

    public static function warnTouchCreateFailed(Frame $frame, string $path): void
    {
        self::triggerWarning(
            $frame,
            \sprintf('touch(): Unable to create file %s because No such file or directory', $path)
        );
    }

    public static function warnRenameFailed(Frame $frame, string $from, string $to): void
    {
        $wrapperMessage = VmFsPhpWrapper::renameWarningMessage($from, $to);
        $message = null !== $wrapperMessage
            ? $wrapperMessage
            : \sprintf('rename(%s,%s): No such file or directory', $from, $to);
        self::triggerWarning($frame, $message);
    }

    public static function warnRmdirNotEmpty(Frame $frame, string $path): void
    {
        self::triggerWarning($frame, \sprintf('rmdir(%s): Directory not empty', $path));
    }

    public static function warnMkdirFileExists(Frame $frame): void
    {
        self::triggerWarningWithHandlerFirst($frame, 'mkdir(): File exists');
    }

    /** php-src filestat.c — recursive mkdir("") warns "Invalid path" (#29359). */
    public static function warnMkdirInvalidPath(Frame $frame): void
    {
        self::triggerWarningWithHandlerFirst($frame, 'mkdir(): Invalid path');
    }

    /**
     * Warning text for a failed mkdir() (php-src filestat.c; #29359).
     *
     * Empty + recursive → "Invalid path"; existing dir → "File exists"; else "No such file…".
     */
    public static function warnMkdirFailed(Frame $frame, string $path, bool $recursive, bool $alreadyDir): void
    {
        if ($alreadyDir) {
            self::warnMkdirFileExists($frame);

            return;
        }
        if ($recursive && '' === $path) {
            self::warnMkdirInvalidPath($frame);

            return;
        }
        self::warnNoSuchFile($frame, 'mkdir');
    }

    public static function warnNoSuchFile(Frame $frame, string $function): void
    {
        self::triggerWarningWithHandlerFirst($frame, \sprintf('%s(): No such file or directory', $function));
    }

    public static function warnInvalidArgument(Frame $frame, string $function): void
    {
        self::triggerWarningWithHandlerFirst($frame, \sprintf('%s(): Invalid argument', $function));
    }

    public static function warnOpendirFailed(Frame $frame, string $path): void
    {
        self::warnPathOpenDirFailed($frame, 'opendir', $path);
    }

    /** php-src dir.c php_opendir — distinguish file vs missing path (#14861, #18418). */
    public static function warnPathOpenDirFailed(Frame $frame, string $function, string $path): void
    {
        self::triggerWarningWithHandlerFirst(
            $frame,
            \sprintf(
                '%s(%s): Failed to open directory: %s',
                $function,
                $path,
                VmDirOpenFailure::openDirFailureReason($path)
            )
        );
    }

    public static function warnChdirFailed(Frame $frame, string $path): void
    {
        self::triggerWarningWithHandlerFirst(
            $frame,
            'chdir(): No such file or directory (errno 2)'
        );
    }

    /** php-src dir.c PHP_FUNCTION(chroot) — failure warning (#29360). */
    public static function warnChrootFailed(Frame $frame): void
    {
        self::triggerWarningWithHandlerFirst(
            $frame,
            'chroot(): No such file or directory (errno 2)'
        );
    }

    public static function warnScandirFailed(Frame $frame, string $path): void
    {
        self::warnPathOpenDirFailed($frame, 'scandir', $path);
        self::triggerWarningWithHandlerFirst($frame, VmDirOpenFailure::scandirFollowupWarning($path));
    }

    public static function warnRmdirMissing(Frame $frame, string $path): void
    {
        self::triggerWarningWithHandlerFirst(
            $frame,
            \sprintf('rmdir(%s): No such file or directory', $path)
        );
    }

    private static function triggerWarningWithHandlerFirst(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerErrorWithHandlerFirst(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private static function triggerWarning(Frame $frame, string $message): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
