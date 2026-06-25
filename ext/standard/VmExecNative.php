<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM exec()/passthru()/system() via libc popen(3) — no host PHP wrappers (#3278, #8533).
 *
 * php-src: ext/standard/exec.c — PHP_FUNCTION(exec), passthru, system
 */
final class VmExecNative
{
    /**
     * Run $command and collect stdout lines (trailing newlines stripped).
     *
     * @return array{lines: list<string>, status: int}|false when popen/pclose fails
     */
    public static function run(string $command): array|false
    {
        if (!VmPopenNative::available()) {
            return false;
        }

        $opened = VmPopenNative::open($command, 'r');
        if (false === $opened) {
            return false;
        }

        $handle = $opened['handle'];
        $lines = [];
        while (!VmFs::feof($handle)) {
            $line = VmFs::fgets($handle);
            if (false === $line) {
                break;
            }
            $lines[] = \rtrim($line, "\r\n");
        }
        VmFs::fclose($handle);

        $status = VmPopenNative::pclose($opened['file']);
        if (-1 === $status) {
            return false;
        }

        return ['lines' => $lines, 'status' => $status];
    }

    /** Echo $data to STDOUT; returns false on write failure. */
    public static function echoToStdout(string $data): bool
    {
        if ('' === $data) {
            return true;
        }
        $written = @\fwrite(\STDOUT, $data);

        return false !== $written && $written === \strlen($data);
    }

    /**
     * @param list<string> $lines
     */
    public static function linesToStdout(array $lines): bool
    {
        foreach ($lines as $line) {
            if (!self::echoToStdout($line."\n")) {
                return false;
            }
        }

        return true;
    }
}
