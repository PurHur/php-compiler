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
        return self::openConnection($hostname, $port, $timeout, $ctx, false);
    }

    public static function sslConnect(string $hostname, int $port, int $timeout, Context $ctx): Variable|false
    {
        return self::openConnection($hostname, $port, $timeout, $ctx, true);
    }

    private static function openConnection(
        string $hostname,
        int $port,
        int $timeout,
        Context $ctx,
        bool $ssl
    ): Variable|false {
        $function = $ssl ? 'ftp_ssl_connect' : 'ftp_connect';
        [$handle, $errno, $errstr] = $ssl
            ? self::clientSsl($hostname, $port, $timeout)
            : VmStreamSocketNative::client(
                self::remoteUri($hostname, $port, 'tcp'),
                (float) $timeout,
                \STREAM_CLIENT_CONNECT
            );
        if (false === $handle) {
            self::warnConnectFailed($function, $errno, $errstr);

            return false;
        }

        $greeting = self::readReplyLine($handle);
        if (null === $greeting || !self::isPositivePreliminary($greeting)) {
            VmFs::fclose($handle);
            self::warnConnectFailed($function, 0, 'Unexpected FTP response');

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
            'ssl' => $ssl,
        ];
        $var->object($object);

        return $var;
    }

    /**
     * @return array{0: int|false, 1: int, 2: string}
     */
    private static function clientSsl(string $hostname, int $port, int $timeout): array
    {
        if (!\function_exists('stream_socket_client')) {
            return [false, 0, 'ssl:// transport is not supported in this compiler build'];
        }

        $remote = self::remoteUri($hostname, $port, 'ssl');
        $errno = 0;
        $errstr = '';
        $sock = @\stream_socket_client($remote, $errno, $errstr, (float) $timeout, \STREAM_CLIENT_CONNECT);
        if (false === $sock) {
            return [false, $errno, '' !== $errstr ? $errstr : 'Connection refused'];
        }

        $handle = VmFs::adoptStreamResource($sock, $remote);
        if (false === $handle) {
            @\fclose($sock);

            return [false, 0, 'Unable to create stream from socket'];
        }

        return [$handle, 0, ''];
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

    private static function remoteUri(string $hostname, int $port, string $transport = 'tcp'): string
    {
        if (-1 === $port) {
            return $transport.'://'.$hostname;
        }

        return $transport.'://'.$hostname.':'.$port;
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

    private static function warnConnectFailed(string $function, int $errno, string $errstr): void
    {
        $detail = '' !== $errstr ? $errstr : 'Connection refused';
        if (0 !== $errno) {
            $detail .= ' ('.$errno.')';
        }
        $message = $function.'(): connect() failed: '.$detail;
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
