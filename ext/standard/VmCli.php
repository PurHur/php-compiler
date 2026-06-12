<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * CLI helpers — process title via PHP + thin libc FFI (php-src ext/standard/cli_ops.c; #5155).
 *
 * Full title is stored in PHP for {@see cli_get_process_title()}; Linux also sets the kernel
 * comm name via prctl(PR_SET_NAME) when FFI is available (16-byte TASK_COMM_LEN truncate).
 */
final class VmCli
{
    private static string $processTitle = '';

    public static function setProcessTitle(string $title): bool
    {
        self::$processTitle = $title;
        VmCliProcessTitleNative::setKernelCommName($title);

        return true;
    }

    public static function getProcessTitle(): string
    {
        return self::$processTitle;
    }
}
