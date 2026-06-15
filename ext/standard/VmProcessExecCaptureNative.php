<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM phpc_run_command() with env replacement — libc fork/pipe/wait, no host proc_open (#8648).
 *
 * Mirrors JIT {@see ProcessRuntime::emitPhpcRunCommand()} / __phpc_process_apply_env.
 * php-src: ext/standard/proc_open.c, exec.c
 */
final class VmProcessExecCaptureNative
{
    private const READ_CHUNK = 8192;

    private const STDOUT_FILENO = 1;

    private const STDERR_FILENO = 2;

    private const EXIT_127 = 127;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{code: int, stdout: string, stderr: string}|null
     */
    public static function runWithEnv(string $command, array $env): ?array
    {
        if ('' === $command) {
            return null;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        try {
            $stdoutPipe = $ffi->new('int[2]');
            $stderrPipe = $ffi->new('int[2]');
            if (0 !== (int) $ffi->pipe($stdoutPipe)) {
                return null;
            }
            if (0 !== (int) $ffi->pipe($stderrPipe)) {
                $ffi->close((int) $stdoutPipe[0]);
                $ffi->close((int) $stdoutPipe[1]);

                return null;
            }

            $pid = (int) $ffi->fork();
            if (-1 === $pid) {
                self::closePipePair($ffi, $stdoutPipe);
                self::closePipePair($ffi, $stderrPipe);

                return null;
            }

            if (0 === $pid) {
                $ffi->close((int) $stdoutPipe[0]);
                $ffi->close((int) $stderrPipe[0]);
                $ffi->dup2((int) $stdoutPipe[1], self::STDOUT_FILENO);
                $ffi->dup2((int) $stderrPipe[1], self::STDERR_FILENO);
                $ffi->close((int) $stdoutPipe[1]);
                $ffi->close((int) $stderrPipe[1]);
                self::applyEnv($ffi, $env);
                $ffi->execl('/bin/sh', 'sh', '-c', $command, null);
                $ffi->_exit(self::EXIT_127);
            }

            $ffi->close((int) $stdoutPipe[1]);
            $ffi->close((int) $stderrPipe[1]);

            $stdout = self::readPipeFd($ffi, (int) $stdoutPipe[0]);
            $stderr = self::readPipeFd($ffi, (int) $stderrPipe[0]);
            $ffi->close((int) $stdoutPipe[0]);
            $ffi->close((int) $stderrPipe[0]);

            $status = $ffi->new('int');
            $waitRc = (int) $ffi->waitpid($pid, \FFI::addr($status), 0);
            if (-1 === $waitRc) {
                return [
                    'code' => self::EXIT_127,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ];
            }

            $statusVal = (int) $status->cdata;
            $exited = 0 === ($statusVal & 0xff);
            $code = $exited ? (($statusVal >> 8) & 0xff) : self::EXIT_127;

            return [
                'code' => $code,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, string> $env
     */
    private static function applyEnv(\FFI $ffi, array $env): void
    {
        foreach ($env as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                continue;
            }
            $ffi->setenv($key, $value, 1);
        }
    }

    private static function readPipeFd(\FFI $ffi, int $fd): string
    {
        $dupFd = (int) $ffi->dup($fd);
        if ($dupFd < 0) {
            return '';
        }

        $handle = VmPhpFdStream::adopt($dupFd, 'pipe://phpc-run-command', 'r');
        if (false === $handle) {
            $ffi->close($dupFd);

            return '';
        }

        $output = '';
        while (!VmPhpFdStream::eof($handle)) {
            $chunk = VmPhpFdStream::read($handle, self::READ_CHUNK);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $output .= $chunk;
        }
        VmPhpFdStream::close($handle);

        return $output;
    }

    /** @param \FFI\CData $pipe int[2] */
    private static function closePipePair(\FFI $ffi, \FFI\CData $pipe): void
    {
        $ffi->close((int) $pipe[0]);
        $ffi->close((int) $pipe[1]);
    }

    private static function ffiEnabled(): bool
    {
        $v = getenv('PHP_COMPILER_DISABLE_FFI');
        if (false !== $v && '' !== $v && '0' !== $v && 'false' !== strtolower($v)) {
            return false;
        }

        return true;
    }

    private static function ffi(): ?\FFI
    {
        if (!self::ffiEnabled()) {
            return null;
        }
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef int pid_t;

int pipe(int pipefd[2]);
pid_t fork(void);
int dup(int oldfd);
int dup2(int oldfd, int newfd);
int close(int fd);
int setenv(const char *name, const char *value, int overwrite);
int execl(const char *path, const char *arg, ...);
void _exit(int status);
pid_t waitpid(pid_t pid, int *status, int options);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
