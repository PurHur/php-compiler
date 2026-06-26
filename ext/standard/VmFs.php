<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\ScriptStack;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/** Host filesystem helpers for stdlib builtins (VM). */
final class VmFs
{
    /** @var array<int, resource> */
    private static array $handles = [];

    /** @var array<int, string> stream URI/path at open time (StreamMetaJit phpc_stream_paths parity; #7908) */
    private static array $handlePaths = [];

    /** @var array<int, int> stream handle => dup(2) socket fd from VmStreamSocketNative (#8202) */
    private static array $handleSocketFds = [];

    /** @var array<int, true> popen() handles — pclose() vs fclose() at libc layer in JIT/AOT */
    private static array $popenHandles = [];

    /** @var array<int, \FFI\CData> libc FILE* for VmPopenNative handles (#8250) */
    private static array $popenNativeFiles = [];

    /** @var array<int, true> gz* stream placeholders — I/O via VmGzStreamPure (#8936, #8220) */
    private static array $gzNativePlaceholders = [];

    /** @var array<int, int> host stream identity => outstanding VM handle ids (#3384 pfsockopen persistent) */
    private static array $hostResourceRefcounts = [];

    private static int $nextHandleId = 0;

    /** Single VM stream handle namespace (php-src php_stream_alloc; fixes #10556 id collisions). */
    public static function allocateStreamHandleId(): int
    {
        return ++self::$nextHandleId;
    }

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

    /** linkinfo() — st_dev from lstat(2) on the link itself (php-src ext/standard/link.c, #6083, #10294). */
    public static function linkinfo(string $path): int
    {
        $stat = VmStatCache::lstat($path);
        if (false === $stat) {
            return -1;
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
        $raw = VmStreamFstat::forHandle($handle);
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
        $values = [];
        foreach ($keys as $i => $key) {
            $values[$i] = (int) ($stat[$key] ?? $stat[$i] ?? 0);
        }
        // php-src filestat.c — numeric indices 0..12 precede string aliases in iteration order.
        foreach ($values as $i => $val) {
            $indexed = new Variable();
            $indexed->int($val);
            $ht->updateIndex($i, $indexed);
        }
        foreach ($keys as $i => $key) {
            $named = new Variable();
            $named->int($values[$i]);
            $ht->add($key, $named);
        }

        return $ht;
    }

    public static function readlink(string $path) {
        return VmFsPathNative::readlink($path);
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
        $ok = VmFsDirNative::mkdir($path, $mode, $recursive);
        if ($ok) {
            VmStatCache::invalidatePath($path);
        }

        return $ok;
    }

    public static function rmdir(string $path): bool
    {
        $ok = VmFsDirNative::rmdir($path);
        if ($ok) {
            VmStatCache::invalidatePath($path);
        }

        return $ok;
    }

    /** True when $path is a directory with entries other than . and .. (php-src ENOTEMPTY parity, #10931). */
    public static function isDirNonempty(string $path): bool
    {
        $names = VmDir::scandir($path, \SCANDIR_SORT_NONE);
        if (false === $names) {
            return false;
        }
        foreach ($names as $name) {
            if ('.' !== $name && '..' !== $name) {
                return true;
            }
        }

        return false;
    }

    public static function chmod(string $path, int $permissions): bool
    {
        $ok = VmFsDirNative::chmod($path, $permissions);
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

            return VmPosix::uidForName($name);
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

            return VmPosix::gidForName($name);
        }

        return null;
    }

    public static function chown(string $path, Variable $user): bool
    {
        $uid = self::resolveUserUid($user);
        if (null === $uid) {
            return false;
        }

        return VmFsDirNative::chown($path, $uid);
    }

    public static function lchown(string $path, Variable $user): bool
    {
        $uid = self::resolveUserUid($user);
        if (null === $uid) {
            return false;
        }

        return VmFsDirNative::lchown($path, $uid);
    }

    public static function chgrp(string $path, Variable $group): bool
    {
        $gid = self::resolveGroupGid($group);
        if (null === $gid) {
            return false;
        }

        return VmFsDirNative::chgrp($path, $gid);
    }

    public static function lchgrp(string $path, Variable $group): bool
    {
        $gid = self::resolveGroupGid($group);
        if (null === $gid) {
            return false;
        }

        return VmFsDirNative::lchgrp($path, $gid);
    }

    public static function rename(string $from, string $to): bool
    {
        $ok = VmFsPathNative::rename($from, $to);
        if ($ok) {
            VmStatCache::invalidatePath($from);
            VmStatCache::invalidatePath($to);
        }

        return $ok;
    }

    public static function hardLink(string $target, string $link): bool
    {
        return VmFsPathNative::link($target, $link);
    }

    public static function symlink(string $target, string $link): bool
    {
        $ok = VmFsPathNative::symlink($target, $link);
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
        if (!VmStatPath::isFile($path)) {
            return false;
        }
        $real = VmStatNative::realpath($path);
        if (false === $real) {
            return false;
        }
        $tmpdir = VmStatNative::realpath(self::tempDir());
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

        return VmFsPathNative::rename($from, $to);
    }

    public static function copy(string $from, string $to): bool
    {
        $ok = VmFsPathNative::copy($from, $to);
        if ($ok) {
            VmStatCache::invalidatePath($to);
        }

        return $ok;
    }

    public static function touch(string $path, ?int $mtime = null, ?int $atime = null): bool
    {
        $ok = VmFsTouchNative::touch($path, $mtime, $atime);
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

    /**
     * @param mixed $streamContext accepted for Zend arity; http wrapper options wired (#9752)
     *
     * @return string|false
     */
    public static function fileGetContents(
        string $path,
        bool $useIncludePath = false,
        mixed $streamContext = null,
        int $offset = 0,
        ?int $length = null,
        ?\PHPCompiler\VM\Context $ctx = null
    ) {
        $httpOptions = [];
        if ($streamContext instanceof \PHPCompiler\VM\Variable) {
            $httpOptions = VmStreamContext::httpWrapperOptions($streamContext);
        }
        if ('php://input' === $path) {
            $body = Superglobals::readRequestBody();
            if (0 === $offset && null === $length) {
                return $body;
            }

            return VmString::byteSlice($body, $offset, $length);
        }
        if (VmDataUri::isDataUri($path)) {
            $data = VmDataUri::decode($path);
            if (false === $data) {
                return false;
            }
            if (0 !== $offset || null !== $length) {
                return VmString::byteSlice($data, $offset, $length);
            }

            return $data;
        }
        if ($useIncludePath) {
            $resolved = self::resolveIncludePath($path);
            if (false !== $resolved) {
                $path = $resolved;
            }
        }
        if (VmStreamWrapperRegistry::isCustomProtocol($path)) {
            if (null === $ctx) {
                return false;
            }
            $handle = VmUserStream::open($ctx->runtime->vm, $ctx, $path, 'r');
            if (false === $handle) {
                return false;
            }
            $data = VmUserStream::readAll($handle);
            VmUserStream::close($handle);
            if (false === $data) {
                return false;
            }
            if (0 !== $offset || null !== $length) {
                return VmString::byteSlice($data, $offset, $length);
            }

            return $data;
        }
        if (VmHttpLastResponseHeaders::isHttpUrl($path)) {
            $data = VmHttpFetchNative::fetch($path, $httpOptions);
            if (false === $data) {
                return false;
            }
            if (0 !== $offset || null !== $length) {
                return VmString::byteSlice($data, $offset, $length);
            }

            return $data;
        }
        if (0 === $offset && null === $length) {
            return VmFsReadNative::read($path);
        }

        return VmFsReadNative::readSlice($path, $offset, $length);
    }

    /**
     * stream_resolve_include_path() — search include_path for filename (ext/standard/streams.c; #6051).
     *
     * @return string|false absolute path when found
     */
    public static function resolveIncludePath(string $filename): string|false
    {
        return IncludePathJitHelper::resolveIncludePathZend($filename);
    }

    /**
     * @return list<string>|false
     */
    public static function file(string $path, int $flags = 0) {
        if (0 !== ($flags & StdlibConstants::FILE_USE_INCLUDE_PATH)) {
            $resolved = self::resolveIncludePath($path);
            if (false !== $resolved) {
                $path = $resolved;
            }
            $flags &= ~StdlibConstants::FILE_USE_INCLUDE_PATH;
        }
        return self::readFileLines($path, $flags);
    }

    /**
     * @return list<string>|false
     */
    private static function readFileLines(string $path, int $flags): array|false
    {
        $content = VmFsReadNative::read($path);
        if (false === $content) {
            return false;
        }
        if ('' === $content) {
            return [];
        }

        $lines = [];
        $offset = 0;
        $len = \strlen($content);
        while ($offset < $len) {
            $pos = \strpos($content, "\n", $offset);
            if (false === $pos) {
                $line = \substr($content, $offset);
                $offset = $len;
            } else {
                $line = \substr($content, $offset, $pos - $offset + 1);
                $offset = $pos + 1;
            }
            if (0 !== ($flags & StdlibConstants::FILE_IGNORE_NEW_LINES)) {
                $line = \rtrim($line, "\r\n");
            }
            if (0 !== ($flags & StdlibConstants::FILE_SKIP_EMPTY_LINES) && '' === $line) {
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    public static function readfile(string $path) {
        $handle = self::fopen($path, 'rb');
        if (false === $handle) {
            return false;
        }
        $total = self::passthruHandleToStdout($handle);
        self::fclose($handle);

        return $total;
    }

    /**
     * Read full path via php_stream_open_wrapper parity (fopen + stream_get_contents + fclose).
     *
     * Use for highlight_file() and other builtins that must open wrapper URIs without
     * bare filesystem reads (php-src ext/standard/url.c; #12095).
     *
     * @return string|false false when open fails
     */
    public static function readPathContentsViaOpen(string $path, ?\PHPCompiler\VM\Context $ctx = null): string|false
    {
        $handle = self::fopen($path, 'rb', $ctx);
        if (false === $handle) {
            return false;
        }
        $data = self::streamGetContents($handle);
        self::fclose($handle);

        return $data;
    }

    /**
     * @param string|list<string> $data
     */
    public static function filePutContents(string $path, $data, int $flags = 0) {
        if (\is_array($data)) {
            $data = implode('', $data);
        }

        $written = VmFsWriteNative::write($path, $data, $flags);
        if (false !== $written) {
            VmStatCache::invalidatePath($path);
        }

        return $written;
    }

    public static function fopen(string $path, string $mode, ?\PHPCompiler\VM\Context $ctx = null) {
        if (VmStreamWrapperRegistry::isCustomProtocol($path)) {
            if (null === $ctx) {
                return false;
            }

            return VmUserStream::open($ctx->runtime->vm, $ctx, $path, $mode);
        }
        if (VmFsStdio::isStdioUri($path)) {
            return VmFsStdio::open($path, $mode);
        }
        if (VmPhpMemoryStream::isSupportedUri($path)) {
            return VmPhpMemoryStream::open($path, $mode);
        }
        if (VmPhpInputOutputStream::isSupportedUri($path)) {
            return VmPhpInputOutputStream::open($path, $mode);
        }
        if (VmPhpFilterStream::isSupportedUri($path)) {
            return VmPhpFilterStream::open($path, $mode, $ctx);
        }
        if (VmPhpFdStream::isFdUri($path)) {
            return VmPhpFdStream::openFromUri($path, $mode);
        }
        if (\str_starts_with($path, 'php://')) {
            return false;
        }
        if (!VmFsOpenNative::available()) {
            return false;
        }

        return VmFsOpenNative::open($path, $mode);
    }

    /**
     * popen() — open pipe to subprocess (php-src ext/standard/exec.c; #6211, #8244, #8951).
     * VmPopenNative with {@see VmPopenPure} fallback when libc FFI unavailable.
     *
     * @return int|false stream handle id
     */
    public static function popen(string $command, string $mode)
    {
        if (VmPopenNative::available()) {
            $opened = VmPopenNative::open($command, $mode);
            if (false === $opened) {
                return false;
            }
            $id = $opened['handle'];
            self::$popenHandles[$id] = true;
            self::$popenNativeFiles[$id] = $opened['file'];

            return $id;
        }

        return false;
    }

    /**
     * pclose() — close popen pipe and return exit status (php-src ext/standard/exec.c; #6211).
     */
    public static function pclose(int $handle): int
    {
        if (!isset(self::$popenHandles[$handle])) {
            return -1;
        }
        $nativeFile = self::$popenNativeFiles[$handle] ?? null;
        unset(self::$popenHandles[$handle], self::$popenNativeFiles[$handle]);
        if (VmPhpFdStream::isValidHandle($handle)) {
            VmPhpFdStream::close($handle);
        } else {
            self::fclose($handle);
        }
        if (null !== $nativeFile) {
            return VmPopenNative::pclose($nativeFile);
        }

        return -1;
    }

    public static function isPopenHandle(int $handle): bool
    {
        return isset(self::$popenHandles[$handle]);
    }

    /** @return int|false */
    public static function adoptStreamResource($resource, string $uri = '', ?int $socketFd = null)
    {
        if (!\is_resource($resource)) {
            return false;
        }
        $id = self::allocateStreamHandleId();
        self::$handles[$id] = $resource;
        self::$handlePaths[$id] = $uri;
        if (null !== $socketFd && $socketFd >= 0) {
            self::$handleSocketFds[$id] = $socketFd;
        }
        self::retainHostResourceRef($resource);

        return $id;
    }

    /**
     * Register a VM stream handle for libz gzFile I/O (#6168, #8220).
     *
     * @return int|false
     */
    public static function adoptGzNativePlaceholder(string $uri)
    {
        $id = VmPhpMemoryStream::open('php://memory', 'r+b');
        if (false === $id) {
            return false;
        }
        self::$handlePaths[$id] = $uri;
        self::$gzNativePlaceholders[$id] = true;

        return $id;
    }

    public static function isGzNativePlaceholder(int $handle): bool
    {
        return isset(self::$gzNativePlaceholders[$handle]);
    }

    public static function releaseGzNativePlaceholder(int $handle): void
    {
        if (!isset(self::$gzNativePlaceholders[$handle])) {
            return;
        }
        unset(self::$gzNativePlaceholders[$handle]);
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            VmPhpMemoryStream::close($handle);
            unset(self::$handlePaths[$handle]);

            return;
        }
        $fp = self::detachStreamHandle($handle);
        if (\is_resource($fp)) {
            @fclose($fp);
        }
    }

    public static function socketFdForHandle(int $handle): ?int
    {
        $fd = VmPhpFdStream::fdForHandle($handle);
        if (null !== $fd) {
            return $fd;
        }

        return self::$handleSocketFds[$handle] ?? null;
    }

    public static function findHandleIdForSocketFd(int $fd): ?int
    {
        foreach (self::$handleSocketFds as $handle => $socketFd) {
            if ($socketFd === $fd) {
                return $handle;
            }
        }

        return null;
    }

    /** @return int|false */
    public static function tmpfile()
    {
        return VmTmpfileNative::open();
    }

    public static function fread(int $handle, int $length) {
        if ($length <= 0) {
            throw new \ValueError('fread(): Argument #2 ($length) must be greater than 0');
        }
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::read($handle, $length);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            $data = VmPhpMemoryStream::read($handle, $length);
            if (false === $data) {
                return false;
            }

            return VmStreamFilterChain::applyReadFilters($handle, $data);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::read($handle, $length);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            $data = VmPhpFdStream::read($handle, $length);
            if (false === $data) {
                return false;
            }

            return VmStreamFilterChain::applyReadFilters($handle, $data);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        $data = @fread($fp, $length);
        if (false === $data) {
            return false;
        }

        return VmStreamFilterChain::applyReadFilters($handle, $data);
    }

    public static function fpassthru(int $handle) {
        return self::passthruHandleToStdout($handle);
    }

    /**
     * Stream remaining bytes from an open VM handle to STDOUT (php_stream_passthru parity).
     *
     * @return int|false Bytes read, or false on I/O failure
     */
    private static function passthruHandleToStdout(int $handle) {
        $total = 0;
        while (!self::feof($handle)) {
            $chunk = self::fread($handle, 8192);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $readLen = \strlen($chunk);
            OutputBuffer::append($chunk);
            $total += $readLen;
        }

        return $total;
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
            OutputBuffer::append($chunk);
            $total += $readLen;
        }

        return $total;
    }

    public static function fwrite(int $handle, string $data, ?int $length = null) {
        if (null !== $length && $length < 0) {
            return 0;
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            $data = VmStreamFilterChain::applyWriteFilters($handle, $data);

            return VmPhpMemoryStream::write($handle, $data, $length);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::write($handle, $data, $length);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            $data = VmStreamFilterChain::applyWriteFilters($handle, $data);

            return VmPhpFdStream::write($handle, $data, $length);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $data = VmStreamFilterChain::applyWriteFilters($handle, $data);
        if (null !== $length && $length < \strlen($data)) {
            $data = substr($data, 0, $length);
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
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::close($handle);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            unset(self::$handlePaths[$handle]);

            return VmPhpMemoryStream::close($handle);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::close($handle);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            VmStreamFilterChain::clearStream($handle);
            VmPersistentSocket::forgetHandle($handle);

            return VmPhpFdStream::close($handle);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        VmStreamFilterChain::clearStream($handle);
        unset(self::$handles[$handle], self::$handlePaths[$handle], self::$handleSocketFds[$handle]);
        if (!self::releaseHostResourceRef($fp)) {
            return true;
        }

        VmPersistentSocket::forgetResource($fp);

        return @fclose($fp);
    }

    /**
     * @param resource|object $resource
     */
    private static function hostResourceKey($resource): int
    {
        if (\is_object($resource)) {
            return \spl_object_id($resource);
        }

        return (int) $resource;
    }

    /**
     * @param resource|object $resource
     */
    private static function retainHostResourceRef($resource): void
    {
        $key = self::hostResourceKey($resource);
        self::$hostResourceRefcounts[$key] = (self::$hostResourceRefcounts[$key] ?? 0) + 1;
    }

    /**
     * @param resource|object $resource
     */
    private static function releaseHostResourceRef($resource): bool
    {
        $key = self::hostResourceKey($resource);
        if (!isset(self::$hostResourceRefcounts[$key])) {
            return true;
        }
        --self::$hostResourceRefcounts[$key];
        if (self::$hostResourceRefcounts[$key] > 0) {
            return false;
        }
        unset(self::$hostResourceRefcounts[$key]);

        return true;
    }

    public static function flock(int $handle, int $operation): bool
    {
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::flock($handle, $operation);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return @\flock($fp, $operation);
    }

    public static function feof(int $handle): bool
    {
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::feof($handle);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::eof($handle);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::eof($handle);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::eof($handle);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return true;
        }

        return \feof($fp);
    }

    public static function fflush(int $handle): bool
    {
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::fflush($handle);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::flush($handle);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::flush($handle);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return @\fflush($fp);
    }

    /** fsync() — flush buffers and sync to disk (php-src ext/standard/file.c, #6062). */
    public static function fsync(int $handle): bool
    {
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::fsync($handle);
        }
        $fp = self::lookup($handle);
        if (null === $fp || !VmStreamSync::isSupported($handle)) {
            return false;
        }
        @\fflush($fp);

        return @\fsync($fp);
    }

    /** fdatasync() — sync file data without metadata (php-src ext/standard/file.c, #6813). */
    public static function fdatasync(int $handle): bool
    {
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::fdatasync($handle);
        }
        $fp = self::lookup($handle);
        if (null === $fp || !VmStreamSync::isSupported($handle)) {
            return false;
        }
        @\fflush($fp);

        return @\fdatasync($fp);
    }

    /**
     * stream_set_chunk_size() — php-src ext/standard/streams.c (issue #3754, #10459).
     *
     * @return int|false previous chunk size
     */
    public static function streamSetChunkSize(int $handle, int $chunkSize) {
        if ($chunkSize <= 0) {
            throw new \ValueError('stream_set_chunk_size(): Argument #2 ($size) must be greater than 0');
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::setChunkSize($handle, $chunkSize);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::setChunkSize($handle, $chunkSize);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::setChunkSize($handle, $chunkSize);
        }
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
        if (!self::isValidHandle($handle)) {
            return false;
        }

        return VmStreamMeta::isLocalUri(self::handleUri($handle));
    }

    /**
     * stream_get_meta_data() — php-src ext/standard/streams.c (issue #6007).
     *
     * @return HashTable|false
     */
    public static function streamGetMetaData(int $handle)
    {
        if (!self::isValidHandle($handle)) {
            return false;
        }
        $uri = self::handleUri($handle);
        $fp = self::lookup($handle);
        if (null !== $fp) {
            $meta = VmStreamMeta::buildMetaArray($uri, $fp);
        } else {
            $meta = VmStreamMeta::buildMetaArray(
                $uri,
                null,
                VmStreamMeta::eofForNativeHandle($handle)
            );
        }

        return self::streamMetaArrayToHashTable($meta);
    }

    /**
     * stream_set_blocking() — php-src ext/standard/streams.c (issue #6007).
     */
    public static function streamSetBlocking(int $handle, bool $mode): bool
    {
        if (VmPhpMemoryStream::isValidHandle($handle)
            || VmPhpInputOutputStream::isValidHandle($handle)
            || VmUserStream::isValidHandle($handle)) {
            return true;
        }
        $fd = self::socketFdForHandle($handle);
        if (null !== $fd) {
            return VmStreamBlockingNative::setBlocking($fd, $mode);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return VmStreamBlockingNative::setBlockingForHostResource($fp, $mode);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function streamMetaArrayToHashTable(array $meta): HashTable
    {
        $ht = new HashTable();
        foreach ($meta as $key => $value) {
            $var = new Variable();
            if (\is_bool($value)) {
                $var->bool($value);
            } else            if (\is_int($value)) {
                $var->int($value);
            } elseif (\is_float($value)) {
                $var->float($value);
            } elseif (\is_string($value)) {
                $var->string($value);
            } else {
                $var->null();
            }
            $ht->add((string) $key, $var);
        }

        return $ht;
    }

    /**
     * stream_supports() — capability probe (php-src php_stream_* option API, issue #5062).
     */
    public static function streamSupports(int $handle, int $feature): bool
    {
        if (!self::isValidHandle($handle)) {
            return false;
        }
        switch ($feature) {
            case VmStreamSupports::STREAM_LOCK:
                $fp = self::lookup($handle);
                if (null === $fp) {
                    return false;
                }

                return \stream_supports_lock($fp);
            case VmStreamSupports::STREAM_META_SEEKABLE:
            case VmStreamSupports::STREAM_FILTER:
                return self::streamSupportsSeekable($handle);
            case VmStreamSupports::STREAM_SUPPORT_TELL:
                return self::streamSupportsTell($handle);
            case VmStreamSupports::STREAM_META_TOUCH:
            case VmStreamSupports::STREAM_META_OWNER_NAME:
            case VmStreamSupports::STREAM_META_OWNER:
            case VmStreamSupports::STREAM_META_GROUP_NAME:
            case VmStreamSupports::STREAM_META_GROUP:
            case VmStreamSupports::STREAM_META_ACCESS:
                return self::streamSupportsMetadata($handle);
            default:
                return false;
        }
    }

    private static function streamSupportsFilter(int $handle): bool
    {
        return VmStreamMeta::supportsFilter(self::handleUri($handle));
    }

    private static function streamSupportsSeekable(int $handle): bool
    {
        return VmStreamMeta::supportsSeekable(self::handleUri($handle));
    }

    private static function streamSupportsTell(int $handle): bool
    {
        return VmStreamMeta::supportsTell(self::handleUri($handle));
    }

    private static function streamSupportsMetadata(int $handle): bool
    {
        return VmStreamMeta::supportsMetadata(self::handleUri($handle));
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

        // php-src: read timeout applies to socket transports; memory/file are no-op success (#3754).
        return !VmStreamMeta::isSocketTransport(self::handleUri($handle));
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
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::tell($handle);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::tell($handle);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::tell($handle);
        }
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
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            $byte = VmPhpMemoryStream::read($handle, 1);
            if (false === $byte) {
                return false;
            }
            if ('' === $byte) {
                return false;
            }

            return $byte;
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            $byte = VmPhpInputOutputStream::read($handle, 1);
            if (false === $byte) {
                return false;
            }
            if ('' === $byte) {
                return false;
            }

            return $byte;
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            $byte = VmPhpFdStream::read($handle, 1);
            if (false === $byte) {
                return false;
            }
            if ('' === $byte) {
                return false;
            }

            return $byte;
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $byte = @\fgetc($fp);
        if (false === $byte) {
            return false;
        }

        return $byte;
    }

    public static function fgets(int $handle, ?int $length = null) {
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::fgets($handle, $length);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::fgets($handle, $length);
        }
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
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::streamGetLine($handle, $maxLength, $ending);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::streamGetLine($handle, $maxLength, $ending);
        }
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
        if (self::isNativeCsvStreamHandle($handle)) {
            $line = VmCsv::formatLine($fields, $separator, $enclosure, $escape)."\n";
            $written = self::fwrite($handle, $line);
            if (false === $written) {
                return false;
            }

            return (int) $written;
        }
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
     * @return list<string|null>|false
     */
    public static function fgetcsv(
        int $handle,
        ?int $length = null,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '\\',
    ) {
        if (self::isNativeCsvStreamHandle($handle)) {
            return self::fgetcsvNative($handle, $length, $separator, $enclosure, $escape);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if (null === $length) {
            $row = @\fgetcsv($fp, separator: $separator, enclosure: $enclosure, escape: $escape);
        } else {
            $row = @\fgetcsv($fp, $length, $separator, $enclosure, $escape);
        }
        if (false === $row) {
            return false;
        }

        return $row;
    }

    private static function isNativeCsvStreamHandle(int $handle): bool
    {
        return VmPhpMemoryStream::isValidHandle($handle)
            || VmPhpInputOutputStream::isValidHandle($handle)
            || VmPhpFdStream::isValidHandle($handle);
    }

    /**
     * fgetcsv() on native VM streams via fgets + VmCsv::parseLine (#5243, StringFgetcsvJit).
     *
     * @return list<string|null>|false
     */
    private static function fgetcsvNative(
        int $handle,
        ?int $length,
        string $separator,
        string $enclosure,
        string $escape,
    ): array|false {
        if (self::feof($handle)) {
            return false;
        }
        $line = self::fgets($handle, $length);
        if (false === $line) {
            return false;
        }
        $line = \rtrim($line, "\r\n");

        return VmCsv::parseLine($line, $separator, $enclosure, $escape);
    }

    public static function fseek(int $handle, int $offset, int $whence = \SEEK_SET): int
    {
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::seek($handle, $offset, $whence);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::seek($handle, $offset, $whence);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::seek($handle, $offset, $whence);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return -1;
        }

        return 0 === @\fseek($fp, $offset, $whence) ? 0 : -1;
    }

    public static function rewind(int $handle): bool
    {
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return 0 === VmPhpMemoryStream::seek($handle, 0, \SEEK_SET);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return 0 === VmPhpInputOutputStream::seek($handle, 0, \SEEK_SET);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return 0 === VmPhpFdStream::seek($handle, 0, \SEEK_SET);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }

        return 0 === @\fseek($fp, 0, \SEEK_SET);
    }

    public static function tempnam(string $directory, string $prefix)
    {
        $pfx = VmFsTempnam::normalizePrefix($prefix);
        $path = VmFsTempnamNative::mkstemp($directory, $pfx);
        if (false !== $path) {
            return $path;
        }

        return false;
    }

    /**
     * stream_get_contents() — read remaining bytes (ext/standard/file.c, #3142).
     *
     * @return string|false
     */
    public static function streamGetContents(int $handle, int $maxlength = -1, int $offset = -1)
    {
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            $data = VmPhpMemoryStream::streamGetContents($handle, $maxlength, $offset);
            if (false === $data) {
                return false;
            }

            return VmStreamFilterChain::applyReadFilters($handle, $data);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::streamGetContents($handle, $maxlength, $offset);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::streamGetContents($handle, $maxlength, $offset);
        }
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

        return VmStreamFilterChain::applyReadFilters($handle, $data);
    }

    /**
     * stream_copy_to_stream() — copy bytes between open streams (ext/standard/streams.c, #3272).
     *
     * @return int|false Bytes copied, or false on I/O failure
     */
    public static function streamCopyToStream(
        int $source,
        int $dest,
        int $maxlength = -1,
        int $offset = 0,
        ?\PHPCompiler\VM\Context $ctx = null,
        ?\PHPCompiler\VM\Variable $streamContext = null
    ) {
        if (VmPhpMemoryStream::isValidHandle($source)
            || VmPhpMemoryStream::isValidHandle($dest)
            || VmUserStream::isValidHandle($source)
            || VmUserStream::isValidHandle($dest)) {
            return self::streamCopyToStreamVm(
                $source,
                $dest,
                $maxlength,
                $offset,
                $ctx,
                $streamContext
            );
        }

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

        $bytesMax = -1;
        if ($maxlength > 0) {
            $bytesMax = $maxlength;
        } else {
            $stat = @\fstat($srcFp);
            if (\is_array($stat) && isset($stat['size'])) {
                $size = (int) $stat['size'];
                if ($offset > 0) {
                    $size = max(0, $size - $offset);
                }
                $bytesMax = $size;
            }
        }

        if (null !== $ctx && null !== VmStreamNotification::resolveForContext($streamContext)) {
            VmStreamNotification::dispatch(
                $ctx,
                $streamContext,
                VmStreamNotification::NOTIFY_FILE_SIZE_IS,
                VmStreamNotification::SEVERITY_INFO,
                '',
                0,
                0,
                max(0, $bytesMax)
            );
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
                if (null !== $ctx) {
                    VmStreamNotification::dispatch(
                        $ctx,
                        $streamContext,
                        VmStreamNotification::NOTIFY_FAILURE,
                        VmStreamNotification::SEVERITY_ERR,
                        'Failed to read from source stream',
                        0,
                        $total,
                        max(0, $bytesMax)
                    );
                }

                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $readLen = \strlen($chunk);
            $written = @\fwrite($dstFp, $chunk);
            if (false === $written) {
                if (null !== $ctx) {
                    VmStreamNotification::dispatch(
                        $ctx,
                        $streamContext,
                        VmStreamNotification::NOTIFY_FAILURE,
                        VmStreamNotification::SEVERITY_ERR,
                        'Failed to write to destination stream',
                        0,
                        $total,
                        max(0, $bytesMax)
                    );
                }

                return false;
            }
            $total += $written;
            if (null !== $ctx && null !== VmStreamNotification::resolveForContext($streamContext)) {
                VmStreamNotification::dispatch(
                    $ctx,
                    $streamContext,
                    VmStreamNotification::NOTIFY_PROGRESS,
                    VmStreamNotification::SEVERITY_INFO,
                    '',
                    0,
                    $total,
                    max(0, $bytesMax)
                );
            }
            if ($written < $readLen) {
                break;
            }
        }

        if (null !== $ctx && null !== VmStreamNotification::resolveForContext($streamContext)) {
            VmStreamNotification::dispatch(
                $ctx,
                $streamContext,
                VmStreamNotification::NOTIFY_COMPLETED,
                VmStreamNotification::SEVERITY_INFO,
                '',
                0,
                $total,
                max(0, $bytesMax)
            );
        }

        return $total;
    }

    /**
     * @return int|false Bytes copied, or false on I/O failure
     */
    private static function streamCopyToStreamVm(
        int $source,
        int $dest,
        int $maxlength,
        int $offset,
        ?\PHPCompiler\VM\Context $ctx,
        ?\PHPCompiler\VM\Variable $streamContext
    ) {
        if ($offset > 0 && 0 !== self::fseek($source, $offset, \SEEK_SET)) {
            return false;
        }
        if (0 === $maxlength) {
            return 0;
        }

        $bytesMax = -1;
        if ($maxlength > 0) {
            $bytesMax = $maxlength;
        } elseif (VmPhpMemoryStream::isValidHandle($source)) {
            $len = VmPhpMemoryStream::bufferLength($source);
            $pos = VmPhpMemoryStream::tell($source);
            if (false !== $len && false !== $pos) {
                $bytesMax = max(0, $len - $pos);
            }
        }

        if (null !== $ctx && null !== VmStreamNotification::resolveForContext($streamContext)) {
            VmStreamNotification::dispatch(
                $ctx,
                $streamContext,
                VmStreamNotification::NOTIFY_FILE_SIZE_IS,
                VmStreamNotification::SEVERITY_INFO,
                '',
                0,
                0,
                max(0, $bytesMax)
            );
        }

        $total = 0;
        $chunkSize = 8192;
        while (!self::feof($source)) {
            if ($maxlength > 0) {
                $remaining = $maxlength - $total;
                if ($remaining <= 0) {
                    break;
                }
                $toRead = min($chunkSize, $remaining);
            } else {
                $toRead = $chunkSize;
            }
            $chunk = self::fread($source, $toRead);
            if (false === $chunk) {
                if (null !== $ctx) {
                    VmStreamNotification::dispatch(
                        $ctx,
                        $streamContext,
                        VmStreamNotification::NOTIFY_FAILURE,
                        VmStreamNotification::SEVERITY_ERR,
                        'Failed to read from source stream',
                        0,
                        $total,
                        max(0, $bytesMax)
                    );
                }

                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $readLen = \strlen($chunk);
            $written = self::fwrite($dest, $chunk);
            if (false === $written) {
                if (null !== $ctx) {
                    VmStreamNotification::dispatch(
                        $ctx,
                        $streamContext,
                        VmStreamNotification::NOTIFY_FAILURE,
                        VmStreamNotification::SEVERITY_ERR,
                        'Failed to write to destination stream',
                        0,
                        $total,
                        max(0, $bytesMax)
                    );
                }

                return false;
            }
            $total += $written;
            if (null !== $ctx && null !== VmStreamNotification::resolveForContext($streamContext)) {
                VmStreamNotification::dispatch(
                    $ctx,
                    $streamContext,
                    VmStreamNotification::NOTIFY_PROGRESS,
                    VmStreamNotification::SEVERITY_INFO,
                    '',
                    0,
                    $total,
                    max(0, $bytesMax)
                );
            }
            if ($written < $readLen) {
                break;
            }
        }

        if (null !== $ctx && null !== VmStreamNotification::resolveForContext($streamContext)) {
            VmStreamNotification::dispatch(
                $ctx,
                $streamContext,
                VmStreamNotification::NOTIFY_COMPLETED,
                VmStreamNotification::SEVERITY_INFO,
                '',
                0,
                $total,
                max(0, $bytesMax)
            );
        }

        return $total;
    }

    /**
     * stream_copy_to_string() — read stream bytes into a string (ext/standard/streams.c, #6547).
     *
     * php-src: PHP_FUNCTION(stream_copy_to_string) — offset defaults to 0 (start of stream).
     *
     * @return string|false
     */
    public static function streamCopyToString(int $handle, int $maxlength = -1, int $offset = 0)
    {
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if ($offset < 0) {
            return false;
        }
        if (0 !== @\fseek($fp, $offset, \SEEK_SET)) {
            return false;
        }
        if (0 === $maxlength) {
            return '';
        }
        $data = '';
        $chunkSize = 8192;
        while (!\feof($fp)) {
            if ($maxlength > 0) {
                $remaining = $maxlength - \strlen($data);
                if ($remaining <= 0) {
                    break;
                }
                $toRead = min($chunkSize, $remaining);
            } else {
                $toRead = $chunkSize;
            }
            $chunk = @\fread($fp, $toRead);
            if (false === $chunk) {
                return false;
            }
            if ('' === $chunk) {
                break;
            }
            $data .= $chunk;
        }

        return VmStreamFilterChain::applyReadFilters($handle, $data);
    }

    /**
     * get_resource_type() for fopen() stream handles (#3142).
     */
    public static function getResourceType(int $handle): ?string
    {
        if (isset(self::$handles[$handle]) || VmPhpMemoryStream::isValidHandle($handle) || VmPhpInputOutputStream::isValidHandle($handle) || VmPhpFdStream::isValidHandle($handle)) {
            return 'stream';
        }

        return null;
    }

    /**
     * get_resource_type() for VM stream-tagged handles, including after fclose (#5179).
     *
     * php-src: ext/standard/file.c — closed resources return "Unknown"
     */
    public static function resourceTypeForStreamTag(int $handle): string
    {
        if (isset(self::$handles[$handle])) {
            return 'stream';
        }
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::protocolForHandle($handle);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return 'stream';
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return 'stream';
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return 'stream';
        }

        return 'Unknown';
    }

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$handles[$handle])
            || VmUserStream::isValidHandle($handle)
            || VmPhpMemoryStream::isValidHandle($handle)
            || VmPhpInputOutputStream::isValidHandle($handle)
            || VmPhpFdStream::isValidHandle($handle);
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
        foreach (VmUserStream::openHandleIds() as $id) {
            $value = new Variable();
            $value->streamHandle($id, $ctx);
            $ht->addIndex($index, $value);
            ++$index;
        }
        foreach (VmPhpMemoryStream::openHandleIds() as $id) {
            $value = new Variable();
            $value->streamHandle($id, $ctx);
            $ht->addIndex($index, $value);
            ++$index;
        }
        foreach (VmPhpInputOutputStream::openHandleIds() as $id) {
            $value = new Variable();
            $value->streamHandle($id, $ctx);
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

    /** Reverse lookup for stream_select() write-back (#3131). */
    public static function handleForHostResource($resource): ?int
    {
        if (!\is_resource($resource)) {
            return null;
        }
        foreach (self::$handles as $id => $fp) {
            if ($fp === $resource) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Remove a stream handle from the VM table without fclose/gzclose (#6168 gzclose).
     *
     * @return resource|null detached host resource
     */
    public static function detachStreamHandle(int $handle): mixed
    {
        if (VmUserStream::isValidHandle($handle)) {
            return null;
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return null;
        }
        VmStreamFilterChain::clearStream($handle);
        unset(self::$handles[$handle], self::$handlePaths[$handle], self::$handleSocketFds[$handle], self::$popenHandles[$handle]);
        self::releaseHostResourceRef($fp);
        VmPersistentSocket::forgetResource($fp);

        return $fp;
    }

    private static function lookup(int $handle): mixed
    {
        return self::$handles[$handle] ?? null;
    }

    public static function handleUri(int $handle): string
    {
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::uriForHandle($handle);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::uriForHandle($handle);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return VmPhpInputOutputStream::uriForHandle($handle);
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return VmPhpFdStream::uriForHandle($handle);
        }

        return self::$handlePaths[$handle] ?? '';
    }

    /** Record fopen URI for JIT/AOT stream handles ({@see StreamPathJitHelper}, #9480). */
    public static function registerStreamPath(int $handle, string $path): void
    {
        if ($handle > 0 && '' !== $path) {
            self::$handlePaths[$handle] = $path;
        }
    }

    public static function clearStreamPath(int $handle): void
    {
        unset(self::$handlePaths[$handle]);
    }

    public static function tempDir(): string
    {
        return VmSysGetTempDirNative::resolve();
    }

    public static function getcwd() {
        return VmGetcwdNative::resolve();
    }

    public static function chdir(string $path): bool
    {
        return VmChdirNative::chdir($path);
    }

    /**
     * disk_free_space() / diskfreespace() — bytes available on filesystem (php-src filestat.c, #3758).
     *
     * @return float|false
     */
    public static function diskFreeSpace(?string $path)
    {
        $path = $path ?? '.';

        return VmFsDiskNative::diskFreeSpace($path);
    }

    /**
     * disk_total_space() / disktotalspace() — total bytes on filesystem (php-src filestat.c, #3758).
     *
     * @return float|false
     */
    public static function diskTotalSpace(?string $path)
    {
        $path = $path ?? '.';

        return VmFsDiskNative::diskTotalSpace($path);
    }
}
