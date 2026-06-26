<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Linux kernel comm name via /proc/self/comm — no prctl FFI (#12170).
 *
 * php-src: ext/standard/cli_ops.c — platform hook for cli_set_process_title
 */
final class VmCliProcessTitlePure
{
    /** Linux TASK_COMM_LEN includes terminating NUL (16 bytes total). */
    private const COMM_MAX_BYTES = 15;

    public static function available(): bool
    {
        return 'Linux' === \PHP_OS_FAMILY && \is_writable('/proc/self/comm');
    }

    public static function setKernelCommName(string $title): void
    {
        if (!self::available()) {
            return;
        }

        $comm = \strlen($title) > self::COMM_MAX_BYTES
            ? \substr($title, 0, self::COMM_MAX_BYTES)
            : $title;

        VmFsWriteNative::write('/proc/self/comm', $comm);
    }
}
