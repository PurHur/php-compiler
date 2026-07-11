<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * shell_exec/escapeshell* / phpc_run_command for compiled JIT/AOT embed modules (#9337, php-in-PHP).
 *
 * SSOT: {@see VmShellExecNative}, {@see VmEscapeshell}, {@see VmPhpcRunCommandNative}
 * php-src: ext/standard/exec.c
 */
final class ProcessJitHelper
{
    private const READ_CHUNK = 8192;

    /** @return string|null null = Zend false / failure */
    public static function shellExecArgv(?string $command): ?string
    {
        if (null === $command || '' === $command) {
            return null;
        }
        $result = VmShellExecNative::shellExec($command);
        if (false === $result) {
            return null;
        }

        return $result;
    }

    public static function escapeshellargArgv(?string $argument): string
    {
        if (null === $argument) {
            return "''";
        }
        VmString::rejectNullByteBuiltinStringArg($argument, 'escapeshellarg', 0, 'arg');

        return VmEscapeshell::escapeshellarg($argument);
    }

    public static function escapeshellcmdArgv(?string $command): string
    {
        if (null === $command) {
            return '';
        }
        VmString::rejectNullByteBuiltinStringArg($command, 'escapeshellcmd', 0, 'command');

        return VmEscapeshell::escapeshellcmd($command);
    }
}
