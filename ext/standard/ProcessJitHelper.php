<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

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

    /** @return HashTable|null hashtable {lines, status} for exec()/passthru()/system() */
    public static function processExecCaptureArgv(?string $command): ?HashTable
    {
        if (null === $command || '' === $command) {
            return null;
        }
        if (!VmPopenNative::available()) {
            return null;
        }

        $opened = VmPopenNative::open($command, 'r');
        if (false === $opened) {
            return null;
        }

        $handle = $opened['handle'];
        $lines = self::readStreamLines($handle);
        VmFs::fclose($handle);
        $status = VmPopenNative::pclose($opened['file']);
        if (-1 === $status) {
            return null;
        }

        $result = new HashTable();
        $linesVar = new Variable();
        $linesVar->array($lines);
        $result->add('lines', $linesVar);
        $statusVar = new Variable();
        $statusVar->int($status);
        $result->add('status', $statusVar);

        return $result;
    }

    private static function readStreamLines(int $handle): HashTable
    {
        $ht = new HashTable();
        $index = 0;
        while (!VmFs::feof($handle)) {
            $line = VmFs::fgets($handle, self::READ_CHUNK);
            if (false === $line) {
                break;
            }
            $line = rtrim($line, "\r\n");
            $var = new Variable();
            $var->string($line);
            $ht->add($index, $var);
            ++$index;
        }

        return $ht;
    }
}
