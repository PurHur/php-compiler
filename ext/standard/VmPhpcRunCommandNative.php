<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM phpc_run_command() without host process API when env is null (#8633, #2779).
 *
 * Uses libc popen(3) via {@see VmPopenNative} — mirrors JIT {@see ProcessRuntime} capture for simple commands.
 * Env replacement uses {@see VmProcessExecCaptureNative} (fork/pipe/wait; #8648).
 */
final class VmPhpcRunCommandNative
{
    private const READ_CHUNK = 8192;

    /**
     * @param array<string, string>|null $env
     *
     * @return array{code: int, stdout: string, stderr: string}|null
     */
    public static function run(string $command, ?array $env = null): ?array
    {
        if ('' === $command) {
            return null;
        }

        if (null !== $env) {
            return VmProcessExecCaptureNative::runWithEnv($command, $env);
        }

        if (!VmPopenNative::available()) {
            return null;
        }

        $opened = VmPopenNative::open($command, 'r');
        if (false === $opened) {
            return null;
        }

        $handle = $opened['handle'];
        $stdout = '';
        while (!VmPhpFdStream::eof($handle)) {
            $chunk = VmPhpFdStream::read($handle, self::READ_CHUNK);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $stdout .= $chunk;
        }
        VmPhpFdStream::close($handle);

        $code = VmPopenNative::pclose($opened['file']);
        if (-1 === $code) {
            return null;
        }

        return [
            'code' => $code,
            'stdout' => $stdout,
            'stderr' => '',
        ];
    }
}
