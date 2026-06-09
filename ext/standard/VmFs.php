<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ScriptStack;
use PHPCompiler\VM\Variable;

/** Host filesystem helpers for stdlib builtins (VM). */
final class VmFs
{
    /** @var array<int, resource> */
    private static array $handles = [];

    /** @var array<int, true> popen() handles — pclose() vs fclose() at libc layer in JIT/AOT */
    private static array $popenHandles = [];

    private static int $nextHandleId = 0;

    /**
     * @param list<string> $names
     */
    public static function stringListToArray(array $names): HashTable
    {
        $ht = new HashTable();
        foreach ($names as $name) {
            $value = new Variable();
            $value->string($name);
            $ht->append($value);
        }

        return $ht;
    }

    /**
     * CSV row cells may be null (empty input line); php-src ext/standard/file.c (#4922).
     *
     * @param list<string|null> $fields
     */
    public static function csvRowToArray(array $fields): HashTable
    {
        $ht = new HashTable();
        foreach ($fields as $field) {
            $value = new Variable();
            if (null === $field) {
                $value->null();
            } else {
                $value->string($field);
            }
            $ht->append($value);
        }

        return $ht;
    }

    public static function fileSize(string $path) {
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['size'];
    }

    public static function fileMtime(string $path) {
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['mtime'];
    }

    public static function fileAtime(string $path) {
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['atime'];
    }

    public static function fileCtime(string $path) {
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['ctime'];
    }

    public static function fileInode(string $path) {
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['ino'];
    }

    /** linkinfo() — st_dev from lstat(2) on the link itself (php-src ext/standard/link.c, #6083). */
    public static function linkinfo(string $path) {
        $stat = VmStatCache::lstat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['dev'];
    }

    public static function fileOwner(string $path) {
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['uid'];
    }

    public static function fileGroup(string $path) {
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['gid'];
    }

    public static function filePerms(string $path) {
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) ($stat['mode'] ?? 0);
    }

    public static function fileType(string $path) {
        $stat = VmStatCache::lstat($path);
        if (false === $stat) {
            return false;
        }

        return self::modeToFiletype((int) ($stat['mode'] ?? 0));
    }

  /**
   * stat()/lstat() array for VM builtins (issue #1197).
   *
   * @return HashTable|false
   */
    public static function statInfo(string $path, bool $lstat = false) {
        $raw = $lstat ? VmStatCache::lstat($path) : VmStatCache::stat($path);
        if (false === $raw) {
            return false;
        }

        return self::phpStatArrayToHashTable($raw);
    }

    /**
     * fstat() array for an open stream handle (issue #3482).
     *
     * @return HashTable|false
     */
    public static function fstat(int $handle) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $raw = @fstat($fp);
        if (false === $raw) {
            return false;
        }

        return self::phpStatArrayToHashTable($raw);
    }

    /**
     * @param array<int|string, int> $stat
     */
    private static function phpStatArrayToHashTable(array $stat): HashTable
    {
        $keys = ['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'atime', 'mtime', 'ctime', 'blksize', 'blocks'];
        $ht = new HashTable();
        foreach ($keys as $i => $key) {
            $val = (int) ($stat[$key] ?? $stat[$i] ?? 0);
            $named = new Variable();
            $named->int($val);
            $ht->add($key, $named);
            $indexed = new Variable();
            $indexed->int($val);
            $ht->updateIndex($i, $indexed);
        }

        return $ht;
    }

    public static function readlink(string $path) {
        $target = @readlink($path);
        if (false === $target) {
            return false;
        }

        return $target;
    }

    public static function unlink(string $path): bool
    {
        $ok = VmFsUnlink::unlink($path);
        if ($ok) {
            VmStatCache::invalidatePath($path);
        }

        return $ok;
    }

    public static function mkdir(string $path, int $mode = 0777, bool $recursive = false): bool
    {
        return @mkdir($path, $mode, $recursive);
    }

    public static function rmdir(string $path): bool
    {
        return @rmdir($path);
    }

    public static function chmod(string $path, int $permissions): bool
    {
        $ok = @chmod($path, $permissions);
        if ($ok) {
            VmStatCache::invalidatePath($path);
        }

        return $ok;
    }

    public static function resolveUserUid(Variable $user): ?int
    {
        if (Variable::TYPE_INTEGER === $user->type) {
            return $user->toInt();
        }
        if (Variable::TYPE_STRING === $user->type) {
            $name = $user->toString();
            if ('' !== $name && ctype_digit($name)) {
                return (int) $name;
            }
            if (\function_exists('posix_getpwnam')) {
                $pw = @posix_getpwnam($name);
                if (\is_array($pw) && isset($pw['uid'])) {
                    return (int) $pw['uid'];
                }
            }

            return null;
        }

        return null;
    }

    public static function resolveGroupGid(Variable $group): ?int
    {
        if (Variable::TYPE_INTEGER === $group->type) {
            return $group->toInt();
        }
        if (Variable::TYPE_STRING === $group->type) {
            $name = $group->toString();
            if ('' !== $name && ctype_digit($name)) {
                return (int) $name;
            }
            if (\function_exists('posix_getgrnam')) {
                $gr = @posix_getgrnam($name);
                if (\is_array($gr) && isset($gr['gid'])) {
                    return (int) $gr['gid'];
                }
            }

            return null;
        }

        return null;
    }

    public static function chown(string $path, Variable $user): bool
    {
        $uid = self::resolveUserUid($user);
        if (null === $uid) {
            return false;
        }

        return @chown($path, $uid);
    }

    public static function lchown(string $path, Variable $user): bool
    {
        $uid = self::resolveUserUid($user);
        if (null === $uid) {
            return false;
        }

        return @lchown($path, $uid);
    }

    public static function chgrp(string $path, Variable $group): bool
    {
        $gid = self::resolveGroupGid($group);
        if (null === $gid) {
            return false;
        }

        return @chgrp($path, $gid);
    }

    public static function lchgrp(string $path, Variable $group): bool
    {
        $gid = self::resolveGroupGid($group);
        if (null === $gid) {
            return false;
        }

        return @lchgrp($path, $gid);
    }

    public static function rename(string $from, string $to): bool
    {
        $ok = @rename($from, $to);
        if ($ok) {
            VmStatCache::invalidatePath($from);
            VmStatCache::invalidatePath($to);
        }

        return $ok;
    }

    public static function hardLink(string $target, string $link): bool
    {
        return @link($target, $link);
    }

    public static function symlink(string $target, string $link): bool
    {
        $ok = @symlink($target, $link);
        if ($ok) {
            VmStatCache::invalidatePath($link);
        }

        return $ok;
    }

    /** Prefix for multipart upload temps (lib/Web/Superglobals.php, AOT sg_set_file_entry). */
    public const UPLOAD_TEMP_PREFIX = 'phpc_upload_';

    public static function pathHasParentTraversal(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return true;
        }
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ('..' === $part) {
                return true;
            }
        }

        return false;
    }

    public static function isValidUploadTempPath(string $path): bool
    {
        if ('' === $path || self::pathHasParentTraversal($path)) {
            return false;
        }
        $base = basename($path);
        if (!str_starts_with($base, self::UPLOAD_TEMP_PREFIX)) {
            return false;
        }
        if (!is_file($path)) {
            return false;
        }
        $real = realpath($path);
        if (false === $real) {
            return false;
        }
        $tmpdir = realpath(self::tempDir());
        if (false === $tmpdir) {
            return false;
        }
        $prefix = $tmpdir.\DIRECTORY_SEPARATOR;

        return str_starts_with($real, $prefix);
    }

    public static function moveUploadedFile(string $from, string $to): bool
    {
        if (!self::isValidUploadTempPath($from) || self::pathHasParentTraversal($to) || '' === $to) {
            return false;
        }

        return @rename($from, $to);
    }

    public static function copy(string $from, string $to): bool
    {
        $ok = @copy($from, $to);
        if ($ok) {
            VmStatCache::invalidatePath($to);
        }

        return $ok;
    }

    public static function touch(string $path, ?int $mtime = null, ?int $atime = null): bool
    {
        if (null === $mtime && null === $atime) {
            $ok = @touch($path);
        } elseif (null === $atime) {
            $ok = @touch($path, $mtime);
        } else {
            $ok = @touch($path, $mtime, $atime);
        }
        if ($ok) {
            VmStatCache::invalidatePath($path);
        }

        return $ok;
    }

    private static function modeToFiletype(int $mode): string
    {
        $type = $mode & 0xF000;

        return match ($type) {
            0x1000 => 'fifo',
            0x2000 => 'char',
            0x4000 => 'dir',
            0x6000 => 'block',
            0x8000 => 'file',
            0xA000 => 'link',
            0xC000 => 'socket',
            default => 'unknown',
        };
    }

    public static function fileGetContents(string $path) {
        if ('php://input' === $path) {
            return false;
        }
        $data = @file_get_contents($path);
        if (VmHttpLastResponseHeaders::isHttpUrl($path)) {
            /** @var list<string> $http_response_header */
            VmHttpLastResponseHeaders::store(
                isset($http_response_header) && \is_array($http_response_header)
                    ? $http_response_header
                    : null
            );
        }
        if (false === $data) {
            return false;
        }

        return $data;
    }

    /**
     * stream_resolve_include_path() — search include_path for filename (ext/standard/streams.c; #6051).
     *
     * @return string|false absolute path when found
     */
    public static function resolveIncludePath(string $filename): string|false
    {
        if ('' === $filename || str_contains($filename, "\0")) {
            return false;
        }
        if ($filename[0] === '/' || (\strlen($filename) > 1 && $filename[1] === ':')) {
            $normalized = ScriptStack::normalize($filename);

            return '' !== $normalized && \is_file($normalized) ? $normalized : false;
        }
        $includePath = VmIncludePath::get();
        if ('' === $includePath) {
            return false;
        }
        foreach (\explode(\PATH_SEPARATOR, $includePath) as $dir) {
            if ('' === $dir) {
                continue;
            }
            $candidate = ScriptStack::normalize(\rtrim($dir, '/\\').'/'.$filename);
            if ('' !== $candidate && \is_file($candidate)) {
                return $candidate;
            }
        }

        return false;
    }

    /**
     * @return list<string>|false
     */
    public static function file(string $path, int $flags = 0) {
        $lines = @\file($path, $flags);
        if (false === $lines) {
            return false;
        }

        return $lines;
    }

    public static function readfile(string $path) {
        $fp = @fopen($path, 'rb');
        if (false === $fp) {
            return false;
        }
        $total = self::passthruStreamToStdout($fp);
        @fclose($fp);

        return $total;
    }

    /**
     * @param string|list<string> $data
     */
    public static function filePutContents(string $path, $data, int $flags = 0) {
        if (\is_array($data)) {
            $data = implode('', $data);
        }
        $written = @file_put_contents($path, $data, $flags);
        if (false === $written) {
            return false;
        }

        return $written;
    }

    public static function fopen(string $path, string $mode) {
        $fp = @fopen($path, $mode);
        if (false === $fp) {
            return false;
        }

        return self::adoptStreamResource($fp);
    }

    /**
     * popen() — open pipe to subprocess (php-src ext/standard/exec.c; #6211).
     *
     * @return int|false stream handle id
     */
    public static function popen(string $command, string $mode)
    {
        $fp = @\popen($command, $mode);
        if (false === $fp) {
            return false;
        }
        $id = self::adoptStreamResource($fp);
        self::$popenHandles[$id] = true;

        return $id;
    }

    /**
     * pclose() — close popen pipe and return exit status (php-src ext/standard/exec.c; #6211).
     */
    public static function pclose(int $handle): int
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return -1;
        }
        unset(self::$handles[$handle]);
        unset(self::$popenHandles[$handle]);
        $result = @\pclose($fp);
        if (false === $result) {
            return -1;
        }

        return (int) $result;
    }

    public static function isPopenHandle(int $handle): bool
    {
        return isset(self::$popenHandles[$handle]);
    }

    /** @return int|false */
    public static function adoptStreamResource($resource)
    {
        if (!\is_resource($resource)) {
            return false;
        }
        $id = ++self::$nextHandleId;
        self::$handles[$id] = $resource;

        return $id;
    }

    /** @return int|false */
    public static function tmpfile()
    {
        $fp = VmTmpfileNative::open();
        if (false === $fp) {
            return false;
        }

        return self::adoptStreamResource($fp);
    }

    public static function fread(int $handle, int $length) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if ($length < 0) {
            return false;
        }

        return @fread($fp, $length);
    }

    public static function fpassthru(int $handle) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return self::passthruStreamToStdout($fp);
    }

    /**
     * Stream remaining bytes from an open file handle to STDOUT (php_stream_passthru parity).
     *
     * @param resource $fp
     *
     * @return int|false Bytes read from $fp, or false on I/O failure
     */
    private static function passthruStreamToStdout($fp) {
        $total = 0;
        while (!feof($fp)) {
            $chunk = @fread($fp, 8192);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $readLen = \strlen($chunk);
            $written = @fwrite(\STDOUT, $chunk);
            if (false === $written || $written !== $readLen) {
                return false;
            }
            $total += $readLen;
        }

        return $total;
    }

    public static function fwrite(int $handle, string $data, ?int $length = null) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if (null === $length) {
            $written = @fwrite($fp, $data);
        } else {
            $written = @fwrite($fp, $data, $length);
        }
        if (false === $written) {
            return false;
        }

        return $written;
    }

    public static function fclose(int $handle): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        unset(self::$handles[$handle]);

        return @fclose($fp);
    }

    public static function flock(int $handle, int $operation): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return @\flock($fp, $operation);
    }

    public static function feof(int $handle): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return true;
        }

        return \feof($fp);
    }

    public static function fflush(int $handle): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return @\fflush($fp);
    }

    /** fsync() — flush buffers and sync to disk (php-src ext/standard/file.c, #6062). */
    public static function fsync(int $handle): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp || !VmStreamSync::isSupportedResource($fp)) {
            return false;
        }
        @\fflush($fp);

        return @\fsync($fp);
    }

    /** fdatasync() — sync file data without metadata (php-src ext/standard/file.c, #6813). */
    public static function fdatasync(int $handle): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp || !VmStreamSync::isSupportedResource($fp)) {
            return false;
        }
        @\fflush($fp);

        return @\fdatasync($fp);
    }

    /**
     * stream_set_chunk_size() — php-src ext/standard/streams.c (issue #3754).
     *
     * @return int|false previous chunk size
     */
    public static function streamSetChunkSize(int $handle, int $chunkSize) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $previous = @\stream_set_chunk_size($fp, $chunkSize);
        if (false === $previous) {
            return false;
        }

        return (int) $previous;
    }

    /**
     * stream_set_write_buffer() — php-src ext/standard/streams.c (issue #3755).
     *
     * @return int|false previous buffer size
     */
    public static function streamSetWriteBuffer(int $handle, int $buffer) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $previous = @\stream_set_write_buffer($fp, $buffer);
        if (false === $previous) {
            return false;
        }

        return (int) $previous;
    }

    /**
     * stream_set_read_buffer() — php-src ext/standard/streams.c (issue #3755).
     *
     * @return int|false previous buffer size
     */
    public static function streamSetReadBuffer(int $handle, int $buffer) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $previous = @\stream_set_read_buffer($fp, $buffer);
        if (false === $previous) {
            return false;
        }

        return (int) $previous;
    }

    /**
     * stream_isatty() — php-src ext/standard/streamsfuncs.c (issue #6035).
     *
     * Returns true when the stream is connected to a terminal (php_stream_isatty).
     */
    public static function streamIsatty(int $handle): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return \stream_isatty($fp);
    }

    /**
     * stream_is_local() — php-src ext/standard/streamsfuncs.c (issue #6173).
     *
     * Returns true when the stream wrapper is not a URL wrapper (php_stream_is_local).
     */
    public static function streamIsLocal(int $handle): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $meta = @\stream_get_meta_data($fp);
        if (!\is_array($meta)) {
            return false;
        }
        $wrapper = \strtolower((string) ($meta['wrapper_type'] ?? ''));

        return !\in_array($wrapper, ['http', 'https', 'ftp', 'ftps'], true);
    }

    /**
     * stream_supports() — capability probe (php-src php_stream_* option API, issue #5062).
     */
    public static function streamSupports(int $handle, int $feature): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        switch ($feature) {
            case VmStreamSupports::STREAM_LOCK:
                return \stream_supports_lock($fp);
            case VmStreamSupports::STREAM_FILTER:
                return self::streamSupportsFilter($fp);
            case VmStreamSupports::STREAM_META_TOUCH:
            case VmStreamSupports::STREAM_META_OWNER_NAME:
            case VmStreamSupports::STREAM_META_OWNER:
            case VmStreamSupports::STREAM_META_GROUP_NAME:
            case VmStreamSupports::STREAM_META_GROUP:
            case VmStreamSupports::STREAM_META_ACCESS:
                return self::streamSupportsMetadata($fp);
            default:
                return false;
        }
    }

    /**
     * @param resource $fp
     */
    private static function streamSupportsFilter($fp): bool
    {
        $meta = @\stream_get_meta_data($fp);
        if (!\is_array($meta)) {
            return false;
        }
        $uri = (string) ($meta['uri'] ?? '');
        if ('php://input' === $uri || 'php://output' === $uri || 'php://stdin' === $uri) {
            return false;
        }

        return true;
    }

    /**
     * @param resource $fp
     */
    private static function streamSupportsMetadata($fp): bool
    {
        $meta = @\stream_get_meta_data($fp);
        if (!\is_array($meta)) {
            return false;
        }
        $wrapper = \strtolower((string) ($meta['wrapper_type'] ?? ''));

        return \in_array($wrapper, ['file', 'plainfile'], true);
    }

    /**
     * stream_set_timeout() — php-src ext/standard/streams.c (issue #3754).
     */
    public static function streamSetTimeout(int $handle, int $seconds, int $microseconds = 0): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if (@\stream_set_timeout($fp, $seconds, $microseconds)) {
            return true;
        }
        $meta = @\stream_get_meta_data($fp);
        if (!\is_array($meta)) {
            return false;
        }
        $streamType = (string) ($meta['stream_type'] ?? '');

        // php-src: read timeout applies to socket transports; memory/file are no-op success (#3754).
        return !\in_array($streamType, ['tcp', 'udp', 'udg', 'unix', 'ssl', 'tls'], true);
    }

    public static function ftruncate(int $handle, int $size): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return @\ftruncate($fp, $size);
    }

    public static function ftell(int $handle) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $pos = @\ftell($fp);
        if (false === $pos) {
            return false;
        }

        return (int) $pos;
    }

    public static function fgetc(int $handle) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $byte = @\fgetc($fp);
        if (false === $byte) {
            if (\feof($fp)) {
                return '';
            }

            return false;
        }

        return $byte;
    }

    public static function fgets(int $handle, ?int $length = null) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if (null === $length) {
            $line = @\fgets($fp);
        } else {
            if ($length <= 0) {
                return false;
            }
            $line = @\fgets($fp, $length);
        }
        if (false === $line) {
            return false;
        }

        return $line;
    }

    /**
     * php-src ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_get_line).
     */
    public static function streamGetLine(int $handle, int $maxLength, ?string $ending = null) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if ($maxLength < 0) {
            return false;
        }
        if (0 === $maxLength) {
            $maxLength = 8192;
        }
        if (null === $ending || '' === $ending) {
            $data = @\fread($fp, $maxLength);
            if (false === $data || ('' === $data && \feof($fp))) {
                return false;
            }

            return $data;
        }

        $result = '';
        $endingLen = \strlen($ending);
        while (\strlen($result) < $maxLength) {
            $byte = @\fgetc($fp);
            if (false === $byte) {
                if ('' === $result && \feof($fp)) {
                    return false;
                }
                break;
            }
            $result .= $byte;
            if ($endingLen > 0 && \substr($result, -$endingLen) === $ending) {
                return \substr($result, 0, -$endingLen);
            }
        }
        if ('' === $result && \feof($fp)) {
            return false;
        }

        return $result;
    }

    /**
     * @param list<string> $fields
     */
    public static function fputcsv(
        int $handle,
        array $fields,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '\\',
    ) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $written = @\fputcsv($fp, $fields, $separator, $enclosure, $escape);
        if (false === $written) {
            return false;
        }

        return (int) $written;
    }

    /**
     * @return list<string>|false
     */
    public static function fgetcsv(
        int $handle,
        ?int $length = null,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '\\',
    ) {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if (null === $length) {
            $row = @\fgetcsv($fp, separator: $separator, enclosure: $enclosure, escape: $escape);
        } else {
            if ($length <= 0) {
                return false;
            }
            $row = @\fgetcsv($fp, $length, $separator, $enclosure, $escape);
        }
        if (false === $row) {
            return false;
        }

        return $row;
    }

    public static function fseek(int $handle, int $offset, int $whence = \SEEK_SET): int
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return -1;
        }

        return 0 === @\fseek($fp, $offset, $whence) ? 0 : -1;
    }

    public static function rewind(int $handle): bool
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return 0 === @\fseek($fp, 0, \SEEK_SET);
    }

    public static function tempnam(string $directory, string $prefix) {
        $path = @\tempnam($directory, $prefix);
        if (false === $path) {
            return false;
        }

        return $path;
    }

    /**
     * stream_get_contents() — read remaining bytes (ext/standard/file.c, #3142).
     *
     * @return string|false
     */
    public static function streamGetContents(int $handle, int $maxlength = -1, int $offset = -1)
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if ($offset < -1) {
            return false;
        }
        if ($offset >= 0 && 0 !== @\fseek($fp, $offset, \SEEK_SET)) {
            return false;
        }
        if ($maxlength < 0) {
            $data = @\stream_get_contents($fp);
        } elseif (0 === $maxlength) {
            return '';
        } else {
            $data = @\stream_get_contents($fp, $maxlength);
        }
        if (false === $data) {
            return false;
        }

        return $data;
    }

    /**
     * stream_copy_to_stream() — copy bytes between open streams (ext/standard/streams.c, #3272).
     *
     * @return int|false Bytes copied, or false on I/O failure
     */
    public static function streamCopyToStream(int $source, int $dest, int $maxlength = -1, int $offset = 0)
    {
        $srcFp = self::lookup($source);
        $dstFp = self::lookup($dest);
        if (null === $srcFp || null === $dstFp) {
            return false;
        }
        if ($offset > 0 && 0 !== @\fseek($srcFp, $offset, \SEEK_SET)) {
            return false;
        }
        if (0 === $maxlength) {
            return 0;
        }
        $total = 0;
        $chunkSize = 8192;
        while (!\feof($srcFp)) {
            if ($maxlength > 0) {
                $remaining = $maxlength - $total;
                if ($remaining <= 0) {
                    break;
                }
                $toRead = min($chunkSize, $remaining);
            } else {
                $toRead = $chunkSize;
            }
            $chunk = @\fread($srcFp, $toRead);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $readLen = \strlen($chunk);
            $written = @\fwrite($dstFp, $chunk);
            if (false === $written) {
                return false;
            }
            $total += $written;
            if ($written < $readLen) {
                break;
            }
        }

        return $total;
    }

    /**
     * get_resource_type() for fopen() stream handles (#3142).
     */
    public static function getResourceType(int $handle): ?string
    {
        if (!isset(self::$handles[$handle])) {
            return null;
        }

        return 'stream';
    }

    /**
     * get_resource_type() for VM stream-tagged handles, including after fclose (#5179).
     *
     * php-src: ext/standard/file.c — closed resources return "Unknown"
     */
    public static function resourceTypeForStreamTag(int $handle): string
    {
        return isset(self::$handles[$handle]) ? 'stream' : 'Unknown';
    }

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$handles[$handle]);
    }

    /**
     * get_resources() — active stream handles (php-src basic_functions.c / zend_list.c, #3646).
     *
     * @throws \ValueError when $type is not a supported resource type filter
     */
    public static function getResourcesTable(?string $type = null, ?\PHPCompiler\VM\Context $ctx = null): HashTable
    {
        if (null !== $type && 'stream' !== $type) {
            throw new \ValueError('get_resources(): Argument #1 ($type) must be a valid resource type');
        }
        $ht = new HashTable();
        $index = 1;
        foreach (self::$handles as $id => $fp) {
            $value = new Variable();
            $value->streamHandle((int) $id, $ctx);
            $ht->addIndex($index, $value);
            ++$index;
        }

        return $ht;
    }

    /** @return resource|null */
    public static function lookupResource(int $handle): mixed
    {
        return self::lookup($handle);
    }

    private static function lookup(int $handle): mixed
    {
        return self::$handles[$handle] ?? null;
    }

    public static function tempDir(): string
    {
        return \sys_get_temp_dir();
    }

    public static function getcwd() {
        return VmGetcwdNative::resolve();
    }

    public static function chdir(string $path): bool
    {
        return @\chdir($path);
    }

    /**
     * disk_free_space() / diskfreespace() — bytes available on filesystem (php-src filestat.c).
     *
     * @return float|false
     */
    public static function diskFreeSpace(?string $path)
    {
        $path = $path ?? '.';
        $result = @\disk_free_space($path);
        if (false === $result) {
            return false;
        }

        return (float) $result;
    }

    /**
     * disk_total_space() / disktotalspace() — total bytes on filesystem (php-src filestat.c).
     *
     * @return float|false
     */
    public static function diskTotalSpace(?string $path)
    {
        $path = $path ?? '.';
        $result = @\disk_total_space($path);
        if (false === $result) {
            return false;
        }

        return (float) $result;
    }
}
