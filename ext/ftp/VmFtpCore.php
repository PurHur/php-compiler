<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPCompiler\ext\standard\VmStreamSocketNative;

/**
 * FTP session state — connect/close + stream API (php-src ext/ftp/ftp.c; #3353, #6762).
 *
 * Prefer host {@code ext/ftp} when available so RETR/STOR/MLSD match Zend; fall back to a
 * thin greeting-only socket when the host module is absent.
 */
final class VmFtpCore
{
    /**
     * @var array<int, array{
     *     handle: int|null,
     *     hostConn: mixed,
     *     host: string,
     *     port: int,
     *     closed: bool,
     *     ssl: bool
     * }>
     */
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
        $connectPort = -1 === $port ? 21 : $port;

        if (self::hostFtpAvailable($ssl)) {
            $hostConn = $ssl
                ? @\ftp_ssl_connect($hostname, $connectPort, $timeout)
                : @\ftp_connect($hostname, $connectPort, $timeout);
            if (false === $hostConn) {
                self::warnConnectFailed($function, 0, 'Connection refused');

                return false;
            }

            return self::wrapConnection($ctx, $hostname, $port, $ssl, null, $hostConn);
        }

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

        return self::wrapConnection($ctx, $hostname, $port, $ssl, $handle, null);
    }

    /**
     * @param resource|\FTP\Connection|null $hostConn
     */
    private static function wrapConnection(
        Context $ctx,
        string $hostname,
        int $port,
        bool $ssl,
        ?int $handle,
        mixed $hostConn
    ): Variable {
        VmFtpConnection::registerClass($ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $object = new ObjectEntry($ctx->classes[VmFtpConnection::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'handle' => $handle,
            'hostConn' => $hostConn,
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
        $state = &self::$state[$connection->id];
        if (null !== $state['hostConn']) {
            @\ftp_close($state['hostConn']);
        } elseif (null !== $state['handle']) {
            self::sendLine($state['handle'], 'QUIT');
            self::readReplyLine($state['handle']);
            VmFs::fclose($state['handle']);
        }
        $state['closed'] = true;
        unset(self::$state[$connection->id]);

        return true;
    }

    public static function login(ObjectEntry $connection, string $username, string $password): bool
    {
        self::ensureLive($connection, 'ftp_login');
        $hostConn = self::requireHostConn($connection, 'ftp_login');

        return (bool) @\ftp_login($hostConn, $username, $password);
    }

    public static function systype(ObjectEntry $connection): string|false
    {
        self::ensureLive($connection, 'ftp_systype');
        $hostConn = self::requireHostConn($connection, 'ftp_systype');
        $result = @\ftp_systype($hostConn);

        return false === $result ? false : (string) $result;
    }

    /**
     * @return HashTable|false
     */
    public static function mlsd(ObjectEntry $connection, string $directory): HashTable|false
    {
        self::ensureLive($connection, 'ftp_mlsd');
        $hostConn = self::requireHostConn($connection, 'ftp_mlsd');
        if (!\function_exists('ftp_mlsd')) {
            return false;
        }
        $rows = @\ftp_mlsd($hostConn, $directory);
        if (false === $rows || !\is_array($rows)) {
            return false;
        }

        $ht = new HashTable();
        $i = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $entry = new Variable();
            $entryHt = new HashTable();
            foreach ($row as $key => $value) {
                $slot = new Variable();
                $slot->string((string) $value);
                $entryHt->add((string) $key, $slot);
            }
            $entry->array($entryHt);
            $ht->add($i, $entry);
            ++$i;
        }

        return $ht;
    }

    public static function fget(
        ObjectEntry $connection,
        int $streamHandle,
        string $remoteFile,
        int $mode,
        int $offset
    ): bool {
        self::ensureLive($connection, 'ftp_fget');
        $hostConn = self::requireHostConn($connection, 'ftp_fget');
        $hostFp = self::hostStreamForWrite($streamHandle);
        if (false === $hostFp) {
            return false;
        }
        $owned = $hostFp['owned'];
        $fp = $hostFp['fp'];
        $ok = (bool) @\ftp_fget($hostConn, $fp, $remoteFile, $mode, $offset);
        if ($owned) {
            if ($ok) {
                \rewind($fp);
                $payload = \stream_get_contents($fp);
                if (false !== $payload && '' !== $payload) {
                    VmFs::fwrite($streamHandle, $payload);
                }
            }
            \fclose($fp);
        }

        return $ok;
    }

    public static function nbContinue(ObjectEntry $connection): int
    {
        self::ensureLive($connection, 'ftp_nb_continue');
        $hostConn = self::requireHostConn($connection, 'ftp_nb_continue');
        if (!\function_exists('ftp_nb_continue')) {
            throw new \LogicException('ftp_nb_continue() requires host ext/ftp (issue #6675)');
        }

        return (int) @\ftp_nb_continue($hostConn);
    }

    public static function nbFget(
        ObjectEntry $connection,
        int $streamHandle,
        string $remoteFile,
        int $mode,
        int $offset
    ): int {
        self::ensureLive($connection, 'ftp_nb_fget');
        $hostConn = self::requireHostConn($connection, 'ftp_nb_fget');
        if (!\function_exists('ftp_nb_fget')) {
            throw new \LogicException('ftp_nb_fget() requires host ext/ftp (issue #6675)');
        }
        $hostFp = self::hostStreamForWrite($streamHandle);
        if (false === $hostFp) {
            return FtpConstants::FTP_FAILED;
        }
        $owned = $hostFp['owned'];
        $fp = $hostFp['fp'];
        $status = (int) @\ftp_nb_fget($hostConn, $fp, $remoteFile, $mode, $offset);
        if ($owned && FtpConstants::FTP_FINISHED === $status) {
            \rewind($fp);
            $payload = \stream_get_contents($fp);
            if (false !== $payload && '' !== $payload) {
                VmFs::fwrite($streamHandle, $payload);
            }
            \fclose($fp);
        } elseif ($owned && FtpConstants::FTP_FAILED === $status) {
            \fclose($fp);
        }

        return $status;
    }

    public static function nbGet(
        ObjectEntry $connection,
        string $localFile,
        string $remoteFile,
        int $mode,
        int $resumePos
    ): int {
        self::ensureLive($connection, 'ftp_nb_get');
        $hostConn = self::requireHostConn($connection, 'ftp_nb_get');
        if (!\function_exists('ftp_nb_get')) {
            throw new \LogicException('ftp_nb_get() requires host ext/ftp (issue #6675)');
        }

        return (int) @\ftp_nb_get($hostConn, $localFile, $remoteFile, $mode, $resumePos);
    }

    public static function nbPut(
        ObjectEntry $connection,
        string $remoteFile,
        string $localFile,
        int $mode,
        int $startPos
    ): int {
        self::ensureLive($connection, 'ftp_nb_put');
        $hostConn = self::requireHostConn($connection, 'ftp_nb_put');
        if (!\function_exists('ftp_nb_put')) {
            throw new \LogicException('ftp_nb_put() requires host ext/ftp (issue #6675)');
        }

        return (int) @\ftp_nb_put($hostConn, $remoteFile, $localFile, $mode, $startPos);
    }

    public static function fput(
        ObjectEntry $connection,
        string $remoteFile,
        int $streamHandle,
        int $mode,
        int $offset
    ): bool {
        self::ensureLive($connection, 'ftp_fput');
        $hostConn = self::requireHostConn($connection, 'ftp_fput');
        $hostFp = self::hostStreamForRead($streamHandle);
        if (false === $hostFp) {
            return false;
        }
        $owned = $hostFp['owned'];
        $fp = $hostFp['fp'];
        $ok = (bool) @\ftp_fput($hostConn, $remoteFile, $fp, $mode, $offset);
        if ($owned) {
            \fclose($fp);
        }

        return $ok;
    }

    public static function pasv(ObjectEntry $connection, bool $enable): bool
    {
        self::ensureLive($connection, 'ftp_pasv');
        $hostConn = self::requireHostConn($connection, 'ftp_pasv');

        return (bool) @\ftp_pasv($hostConn, $enable);
    }

    public static function get(
        ObjectEntry $connection,
        string $localFile,
        string $remoteFile,
        int $mode,
        int $offset
    ): bool {
        self::ensureLive($connection, 'ftp_get');
        $hostConn = self::requireHostConn($connection, 'ftp_get');

        return (bool) @\ftp_get($hostConn, $localFile, $remoteFile, $mode, $offset);
    }

    public static function put(
        ObjectEntry $connection,
        string $remoteFile,
        string $localFile,
        int $mode,
        int $offset
    ): bool {
        self::ensureLive($connection, 'ftp_put');
        $hostConn = self::requireHostConn($connection, 'ftp_put');

        return (bool) @\ftp_put($hostConn, $remoteFile, $localFile, $mode, $offset);
    }

    /**
     * @return HashTable|false
     */
    public static function nlist(ObjectEntry $connection, string $directory): HashTable|false
    {
        self::ensureLive($connection, 'ftp_nlist');
        $hostConn = self::requireHostConn($connection, 'ftp_nlist');
        $rows = @\ftp_nlist($hostConn, $directory);
        if (false === $rows || !\is_array($rows)) {
            return false;
        }

        return self::stringListToHashTable($rows);
    }

    /**
     * @return HashTable|false
     */
    public static function rawlist(ObjectEntry $connection, string $directory, bool $recursive): HashTable|false
    {
        self::ensureLive($connection, 'ftp_rawlist');
        $hostConn = self::requireHostConn($connection, 'ftp_rawlist');
        $rows = @\ftp_rawlist($hostConn, $directory, $recursive);
        if (false === $rows || !\is_array($rows)) {
            return false;
        }

        return self::stringListToHashTable($rows);
    }

    public static function chdir(ObjectEntry $connection, string $directory): bool
    {
        self::ensureLive($connection, 'ftp_chdir');
        $hostConn = self::requireHostConn($connection, 'ftp_chdir');

        return (bool) @\ftp_chdir($hostConn, $directory);
    }

    public static function mkdir(ObjectEntry $connection, string $directory): string|false
    {
        self::ensureLive($connection, 'ftp_mkdir');
        $hostConn = self::requireHostConn($connection, 'ftp_mkdir');
        $result = @\ftp_mkdir($hostConn, $directory);

        return false === $result ? false : (string) $result;
    }

    public static function delete(ObjectEntry $connection, string $filename): bool
    {
        self::ensureLive($connection, 'ftp_delete');
        $hostConn = self::requireHostConn($connection, 'ftp_delete');

        return (bool) @\ftp_delete($hostConn, $filename);
    }

    public static function size(ObjectEntry $connection, string $filename): int
    {
        self::ensureLive($connection, 'ftp_size');
        $hostConn = self::requireHostConn($connection, 'ftp_size');

        return (int) @\ftp_size($hostConn, $filename);
    }

    public static function mdtm(ObjectEntry $connection, string $filename): int
    {
        self::ensureLive($connection, 'ftp_mdtm');
        $hostConn = self::requireHostConn($connection, 'ftp_mdtm');

        return (int) @\ftp_mdtm($hostConn, $filename);
    }

    /**
     * @param list<string> $rows
     */
    private static function stringListToHashTable(array $rows): HashTable
    {
        $ht = new HashTable();
        $i = 0;
        foreach ($rows as $row) {
            $slot = new Variable();
            $slot->string((string) $row);
            $ht->add($i, $slot);
            ++$i;
        }

        return $ht;
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
        $handle = self::$state[$connection->id]['handle'];
        if (null === $handle) {
            throw new \LogicException('ftp_*: socket fallback handle unavailable (host FTP connection)');
        }

        return $handle;
    }

    private static function hostFtpAvailable(bool $ssl): bool
    {
        if ($ssl) {
            return \function_exists('ftp_ssl_connect');
        }

        return \function_exists('ftp_connect');
    }

    /**
     * @return resource|\FTP\Connection
     */
    private static function requireHostConn(ObjectEntry $connection, string $function): mixed
    {
        $hostConn = self::$state[$connection->id]['hostConn'] ?? null;
        if (null === $hostConn) {
            throw new \LogicException($function.'() requires host ext/ftp (issue #6762)');
        }

        return $hostConn;
    }

    /**
     * @return array{fp: resource, owned: bool}|false
     */
    private static function hostStreamForWrite(int $streamHandle): array|false
    {
        $fp = VmFs::lookupResource($streamHandle);
        if (\is_resource($fp)) {
            return ['fp' => $fp, 'owned' => false];
        }
        if (VmPhpMemoryStream::isValidHandle($streamHandle) || VmFs::isValidHandle($streamHandle)) {
            $tmp = @\fopen('php://memory', 'r+b');
            if (false === $tmp) {
                return false;
            }

            return ['fp' => $tmp, 'owned' => true];
        }

        return false;
    }

    /**
     * @return array{fp: resource, owned: bool}|false
     */
    private static function hostStreamForRead(int $streamHandle): array|false
    {
        $fp = VmFs::lookupResource($streamHandle);
        if (\is_resource($fp)) {
            return ['fp' => $fp, 'owned' => false];
        }
        if (VmPhpMemoryStream::isValidHandle($streamHandle)) {
            $payload = VmFs::streamGetContents($streamHandle);
            if (false === $payload) {
                $payload = '';
            }
            $tmp = @\fopen('php://memory', 'r+b');
            if (false === $tmp) {
                return false;
            }
            \fwrite($tmp, $payload);
            \rewind($tmp);

            return ['fp' => $tmp, 'owned' => true];
        }

        return false;
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
