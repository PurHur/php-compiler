<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM shell_exec() via libc popen(3) — no host PHP \shell_exec() (#8250, #5348).
 *
 * php-src: ext/standard/exec.c — PHP_FUNCTION(shell_exec)
 * JIT/AOT: {@see JitShellExec} / __compiler_shell_exec via ProcessRuntime.
 */
final class VmShellExecNative
{
    private const READ_CHUNK = 8192;

    public static function shellExec(string $command): string|false|null
    {
        if (!VmPopenNative::available()) {
            return false;
        }

        $opened = VmPopenNative::open($command, 'r');
        if (false === $opened) {
            return null;
        }

        $stream = $opened['stream'];
        $output = '';
        while (!\feof($stream)) {
            $chunk = @\fread($stream, self::READ_CHUNK);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $output .= $chunk;
        }
        @\fclose($stream);

        $status = VmPopenNative::pclose($opened['file']);
        if (-1 === $status) {
            return false;
        }
        if ('' === $output) {
            return null;
        }

        return $output;
    }
}
