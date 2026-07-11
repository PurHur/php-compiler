<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Linux prctl(PR_SET_NAME) for cli_set_process_title() — pure PHP via {@see VmCliProcessTitlePure} (#5155, #12170).
 *
 * php-src: ext/standard/cli_ops.c — platform hook; PHP owns title storage in {@see VmCli}.
 */
final class VmCliProcessTitleNative
{
    public static function available(): bool
    {
        return VmCliProcessTitlePure::available();
    }

    public static function setKernelCommName(string $title): void
    {
        VmCliProcessTitlePure::setKernelCommName($title);
    }
}
