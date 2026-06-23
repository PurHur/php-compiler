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
        self::triggerWarning($frame, \sprintf('chmod(): No such file or directory'));
    }

    public static function warnUnlinkFailed(Frame $frame, string $path): void
    {
        self::triggerWarning($frame, \sprintf('unlink(%s): No such file or directory', $path));
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
        self::triggerWarning(
            $frame,
            \sprintf('rename(%s,%s): No such file or directory', $from, $to)
        );
    }

    public static function warnRmdirNotEmpty(Frame $frame, string $path): void
    {
        self::triggerWarning($frame, \sprintf('rmdir(%s): Directory not empty', $path));
    }

    public static function warnMkdirFileExists(Frame $frame): void
    {
        self::triggerWarningWithHandlerFirst($frame, 'mkdir(): File exists');
    }

    public static function warnNoSuchFile(Frame $frame, string $function): void
    {
        self::triggerWarningWithHandlerFirst($frame, \sprintf('%s(): No such file or directory', $function));
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
