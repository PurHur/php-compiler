<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Host filesystem helpers for stdlib builtins (VM). */
final class VmFs
{
    /** @var array<int, resource> */
    private static array $handles = [];

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

    public static function fileSize(string $path) {
        $stat = @stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['size'];
    }

    public static function fileMtime(string $path) {
        $stat = @stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) $stat['mtime'];
    }

    public static function filePerms(string $path) {
        $stat = @stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) ($stat['mode'] ?? 0);
    }

    public static function fileType(string $path) {
        $stat = @lstat($path);
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
        $raw = $lstat ? @lstat($path) : @stat($path);
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
        return @unlink($path);
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
        return @chmod($path, $permissions);
    }

    public static function rename(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    public static function hardLink(string $target, string $link): bool
    {
        return @link($target, $link);
    }

    public static function symlink(string $target, string $link): bool
    {
        return @symlink($target, $link);
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
        return @copy($from, $to);
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
            \clearstatcache(true, $path);
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
        if (false === $data) {
            return false;
        }

        return $data;
    }

    public static function readfile(string $path) {
        $fp = @fopen($path, 'rb');
        if (false === $fp) {
            return false;
        }
        $total = 0;
        while (!feof($fp)) {
            $chunk = @fread($fp, 8192);
            if (false === $chunk) {
                @fclose($fp);

                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $written = @fwrite(\STDOUT, $chunk);
            if (false === $written) {
                @fclose($fp);

                return false;
            }
            $total += $written;
        }
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
        $id = ++self::$nextHandleId;
        self::$handles[$id] = $fp;

        return $id;
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
        $total = 0;
        while (!feof($fp)) {
            $chunk = @fread($fp, 8192);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $written = @fwrite(\STDOUT, $chunk);
            if (false === $written) {
                return false;
            }
            $total += $written;
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

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$handles[$handle]);
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
        $cwd = @\getcwd();
        if (false === $cwd) {
            return false;
        }

        return $cwd;
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
