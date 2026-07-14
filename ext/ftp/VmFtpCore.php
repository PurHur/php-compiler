<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamSocketNative;

/**
 * Minimal FTP session state — connect + close (php-src ext/ftp/ftp.c; #3353 phase 1).
 */
final class VmFtpCore
{
    /** @var array<int, array{handle: int, host: string, port: int, closed: bool}> */
    private static array $state = [];

    public static function connect(string $hostname, int $port, int $timeout, Context $ctx): Variable|false
    {
        $remote = self::remoteUri($hostname, $port);
        [$handle, $errno, $errstr] = VmStreamSocketNative::client(
            $remote,
            (float) $timeout,
            \STREAM_CLIENT_CONNECT
        );
        if (false === $handle) {
            self::warnConnectFailed($hostname, $port, $errno, $errstr);

            return false;
        }

        $greeting = self::readReplyLine($handle);
        if (null === $greeting || !self::isPositivePreliminary($greeting)) {
            VmFs::fclose($handle);
            self::warnConnectFailed($hostname, $port, 0, 'Unexpected FTP response');

            return false;
        }

        VmFtpConnection::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[VmFtpConnection::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'handle' => $handle,
            'host' => $hostname,
            'port' => $port,
            'closed' => false,
        ];
        $var->object($object);

        return $var;
    }

    public static function close(ObjectEntry $connection): bool
    {
        self::ensureLive($connection, 'ftp_close');
        $handle = self::$state[$connection->id]['handle'];
        self::sendLine($handle, 'QUIT');
        self::readReplyLine($handle);
        VmFs::fclose($handle);
        self::$state[$connection->id]['closed'] = true;
        unset(self::$state[$connection->id]);

        return true;
    }

    public static function isConnectionObject(?ObjectEntry $object): bool
    {
        return null !== $object && VmFtpConnection::CLASS_LC === strtolower($object->class->name);
    }

    public static function isLiveConnectionObject(ObjectEntry $object): bool
    {
        return self::isConnectionObject($object)
            && isset(self::$state[$object->id])
            && !self::$state[$object->id]['closed'];
    }

    public static function streamHandleForConnection(ObjectEntry $connection): int
    {
        self::ensureLive($connection, 'ftp_*');

        return self::$state[$connection->id]['handle'];
    }

    private static function ensureLive(ObjectEntry $connection, string $function): void
    {
        if (!self::isConnectionObject($connection)) {
            throw new \TypeError($function.'(): Argument #1 ($ftp) must be of type FTP\\Connection');
        }
        if (!isset(self::$state[$connection->id]) || self::$state[$connection->id]['closed']) {
            throw new \TypeError('supplied resource is not a valid FTP Connection resource');
        }
    }

    private static function remoteUri(string $hostname, int $port): string
    {
        if (-1 === $port) {
            return 'tcp://'.$hostname;
        }

        return 'tcp://'.$hostname.':'.$port;
    }

    private static function readReplyLine(int $handle): ?string
    {
        $line = VmFs::fgets($handle, 512);
        if (false === $line || '' === $line) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    private static function sendLine(int $handle, string $command): void
    {
        VmFs::fwrite($handle, $command."\r\n");
    }

    private static function isPositivePreliminary(string $line): bool
    {
        if (strlen($line) < 3 || !ctype_digit(substr($line, 0, 3))) {
            return false;
        }

        $code = (int) substr($line, 0, 3);

        return 220 === $code || (120 <= $code && $code < 200);
    }

    private static function warnConnectFailed(string $hostname, int $port, int $errno, string $errstr): void
    {
        unset($hostname, $port);
        $detail = '' !== $errstr ? $errstr : 'Connection refused';
        if (0 !== $errno) {
            $detail .= ' ('.$errno.')';
        }
        $message = 'ftp_connect(): connect() failed: '.$detail;
        $vm = VM::running();
        if (null === $vm) {
            @\trigger_error($message, \E_WARNING);

            return;
        }
        $frame = $vm->builtinHandlerFrame();
        if (null === $frame) {
            $frames = $vm->context->runStackFrames();
            $frame = [] !== $frames ? $frames[0] : null;
        }
        $vm->context->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            null,
            $vm->context,
            $frame
        );
    }
}
