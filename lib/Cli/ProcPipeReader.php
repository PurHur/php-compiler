<?php

declare(strict_types=1);

namespace PHPCompiler\Cli;

/**
 * Multiplexed drain of proc_open stdout/stderr pipes (#36251).
 *
 * Reading one pipe to EOF before the other deadlocks when the child writes more
 * than the kernel pipe buffer (~64 KiB) to the unread pipe while the parent is
 * blocked on the first.
 */
final class ProcPipeReader
{
    /**
     * @param resource $proc
     * @param resource $stdout
     * @param resource $stderr
     *
     * @return array{stdout: string, stderr: string, exitcode: int}
     */
    public static function readUntilProcessExit($proc, $stdout, $stderr): array
    {
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);

        $out = '';
        $err = '';
        $exitcode = 1;
        while (true) {
            $read = [];
            if (!feof($stdout)) {
                $read[] = $stdout;
            }
            if (!feof($stderr)) {
                $read[] = $stderr;
            }

            if ([] !== $read) {
                $write = null;
                $except = null;
                $ready = @stream_select($read, $write, $except, 1, 0);
                if (false !== $ready && $ready > 0) {
                    foreach ($read as $stream) {
                        $chunk = fread($stream, 8192);
                        if (false === $chunk || '' === $chunk) {
                            continue;
                        }
                        if ($stream === $stdout) {
                            $out .= $chunk;
                        } else {
                            $err .= $chunk;
                        }
                    }
                }
            }

            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exitcode = (int) $status['exitcode'];
                $out .= (string) stream_get_contents($stdout);
                $err .= (string) stream_get_contents($stderr);
                break;
            }

            if ([] === $read) {
                usleep(10_000);
            }
        }

        return [
            'stdout' => $out,
            'stderr' => $err,
            'exitcode' => $exitcode,
        ];
    }
}
