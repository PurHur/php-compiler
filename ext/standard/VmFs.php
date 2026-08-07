<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\VM;
use PHPCompiler\VM\ErrorReporter;
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

    /** @var array<int, string> user fopen mode at open time (stream_get_meta_data mode; #13021) */
    private static array $handleModes = [];

    /** @var array<int, bool> stream blocking flag for stream_get_meta_data blocked key (#13724) */
    private static array $handleBlocked = [];

    /** @var array<int, int> stream handle => dup(2) socket fd from VmStreamSocketNative (#8202) */
    private static array $handleSocketFds = [];

    /** @var array<int, true> popen() handles — pclose() vs fclose() at libc layer in JIT/AOT */
    private static array $popenHandles = [];

    /** @var array<int, string> unread bytes after scanf over-read on non-seekable streams (#15992) */
    private static array $readPushback = [];

    /** @var array<int, int> pclose tokens from VmPopenPure (#8250, #12266) */
    private static array $popenNativeFiles = [];

    /** @var array<int, true> gz* stream placeholders — I/O via VmGzStreamPure (#8936, #8220) */
    private static array $gzNativePlaceholders = [];

    /** @var array<int, true> bz* stream placeholders — I/O via VmBz2StreamPure (#17301) */
    private static array $bzNativePlaceholders = [];

    /** @var array<int, string> zip_open() archive placeholders — state in VmZipProcedural (#6370) */
    private static array $zipArchivePlaceholders = [];

    /** @var array<int, string> tmpfile() paths to unlink on fclose (#24786, php-src file.c) */
    private static array $tmpfileUnlinkPaths = [];

    /** @var array<int, int> zip_read() entry placeholders — parent archive handle (#6370) */
    private static array $zipEntryPlaceholders = [];

    /** @var array<int, int> host stream identity => outstanding VM handle ids (#3384 pfsockopen persistent) */
    private static array $hostResourceRefcounts = [];

    private static int $nextHandleId = 0;

    /** @var array<int, true> bogus stream resources after invalid mode on built-in wrappers (#13401) */
    private static array $failedStreamHandles = [];

    /** Single VM stream handle namespace (php-src php_stream_alloc; fixes #10556 id collisions). */
    public static function allocateStreamHandleId(): int
    {
        return ++self::$nextHandleId;
    }

    /**
     * Zend fopen on registered wrapper with invalid $mode — non-false resource sentinel (#13401).
     */
    public static function allocateFailedStreamHandle(): int
    {
        $id = self::allocateStreamHandleId();
        self::$failedStreamHandles[$id] = true;

        return $id;
    }

    public static function isFailedStreamHandle(int $handle): bool
    {
        return isset(self::$failedStreamHandles[$handle]);
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
        $wrapperOk = VmUserStream::tryUnlink($path);
        if (null !== $wrapperOk) {
            if ($wrapperOk) {
                VmStatCache::invalidatePath($path);
            }

            return $wrapperOk;
        }
        $ok = VmFsUnlink::unlink($path);
        if ($ok) {
            VmStatCache::invalidatePath($path);
        }

        return $ok;
    }

    public static function mkdir(string $path, int $mode = 0777, bool $recursive = false): bool
    {
        $wrapperOk = VmUserStream::tryMkdir($path, $mode, $recursive);
        if (null !== $wrapperOk) {
            if ($wrapperOk) {
                VmStatCache::invalidatePath($path);
            }

            return $wrapperOk;
        }
        $ok = VmFsDirNative::mkdir($path, $mode, $recursive);
        if ($ok) {
            VmStatCache::invalidatePath($path);
        }

        return $ok;
    }

    public static function rmdir(string $path): bool
    {
        $wrapperOk = VmUserStream::tryRmdir($path);
        if (null !== $wrapperOk) {
            if ($wrapperOk) {
                VmStatCache::invalidatePath($path);
            }

            return $wrapperOk;
        }
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
        $modeVar = new Variable();
        $modeVar->int($permissions);
        $wrapperOk = VmStreamWrapperMetadata::tryInvoke(
            $path,
            VmStreamSupports::STREAM_META_ACCESS,
            $modeVar
        );
        if (null !== $wrapperOk) {
            if ($wrapperOk) {
                VmStatCache::invalidateNegative($path);
            }

            return $wrapperOk;
        }

        $ok = VmFsDirNative::chmod($path, $permissions);
        if ($ok) {
            // php-src keeps positive stats until clearstatcache; only clear misses (#22841).
            VmStatCache::invalidateNegative($path);
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
        $user = $user->resolveIndirect();
        if (Variable::TYPE_STRING === $user->type) {
            $wrapperOk = VmStreamWrapperMetadata::tryInvoke(
                $path,
                VmStreamSupports::STREAM_META_OWNER_NAME,
                $user
            );
            if (null !== $wrapperOk) {
                return $wrapperOk;
            }
        }
        $uid = self::resolveUserUid($user);
        if (null === $uid) {
            return false;
        }
        $uidVar = new Variable();
        $uidVar->int($uid);
        $wrapperOk = VmStreamWrapperMetadata::tryInvoke(
            $path,
            VmStreamSupports::STREAM_META_OWNER,
            $uidVar
        );
        if (null !== $wrapperOk) {
            return $wrapperOk;
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
        $group = $group->resolveIndirect();
        if (Variable::TYPE_STRING === $group->type) {
            $wrapperOk = VmStreamWrapperMetadata::tryInvoke(
                $path,
                VmStreamSupports::STREAM_META_GROUP_NAME,
                $group
            );
            if (null !== $wrapperOk) {
                return $wrapperOk;
            }
        }
        $gid = self::resolveGroupGid($group);
        if (null === $gid) {
            return false;
        }
        $gidVar = new Variable();
        $gidVar->int($gid);
        $wrapperOk = VmStreamWrapperMetadata::tryInvoke(
            $path,
            VmStreamSupports::STREAM_META_GROUP,
            $gidVar
        );
        if (null !== $wrapperOk) {
            return $wrapperOk;
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
        $wrapperOk = VmUserStream::tryRename($from, $to);
        if (null !== $wrapperOk) {
            if ($wrapperOk) {
                VmStatCache::invalidatePath($from);
                VmStatCache::invalidatePath($to);
            }

            return $wrapperOk;
        }
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
        if (self::pathRequiresStreamOpen($from) || self::pathRequiresStreamOpen($to)) {
            return self::copyViaStreamOpen($from, $to);
        }

        $ok = VmFsPathNative::copy($from, $to);
        if ($ok) {
            // Overwrite keeps Zend positive cache; creation after a miss must refresh (#22841).
            VmStatCache::invalidateNegative($to);
        }

        return $ok;
    }

    private static function pathRequiresStreamOpen(string $path): bool
    {
        return VmDataUri::isDataUri($path)
            || VmPhpMemoryStream::isSupportedUri($path)
            || VmPhpInputOutputStream::isSupportedUri($path)
            || VmFsStdio::isStdioUri($path)
            || VmPhpFilterStream::isSupportedUri($path)
            || VmHttpLastResponseHeaders::isHttpUrl($path)
            || VmStreamWrapperRegistry::isCustomProtocol($path)
            || \PHPCompiler\ext\brotli\VmBrotliStream::isSupportedUri($path);
    }

    private static function copyViaStreamOpen(string $from, string $to): bool
    {
        $src = self::fopen($from, 'rb');
        if (false === $src) {
            return false;
        }
        $dst = self::fopen($to, 'wb');
        if (false === $dst) {
            self::fclose($src);

            return false;
        }
        $copied = self::streamCopyToStream($src, $dst);
        self::fclose($src);
        self::fclose($dst);
        if (false === $copied) {
            return false;
        }
        VmStatCache::invalidateNegative($to);

        return true;
    }

    public static function touch(string $path, ?int $mtime = null, ?int $atime = null): bool
    {
        $wrapperOk = VmStreamWrapperMetadata::tryInvoke(
            $path,
            VmStreamSupports::STREAM_META_TOUCH,
            VmStreamWrapperMetadata::touchValue($mtime, $atime)
        );
        if (null !== $wrapperOk) {
            if ($wrapperOk) {
                // php-src keeps positive stats until clearstatcache; only clear misses (#25853).
                // (#25308 over-invalidated vs Zend: prior filemtime then touch must stay stale.)
                VmStatCache::invalidateNegative($path);
            }

            return $wrapperOk;
        }

        $ok = VmFsTouchNative::touch($path, $mtime, $atime);
        if ($ok) {
            // Same as chmod/copy content writes (#22841): drop negative hits only.
            VmStatCache::invalidateNegative($path);
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
        $path = self::rewritePharInterceptPath($path);
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
        if (VmFsStdio::isStdioUri($path) || 'php://output' === $path) {
            if ('php://stdin' === $path) {
                $data = self::readPathContentsViaOpen($path, $ctx);
            } else {
                // php://stdout|stderr|output — write-only; Zend file_get_contents returns '' (ext/standard/file.c).
                $data = '';
            }
            if (false === $data) {
                return false;
            }
            if (0 !== $offset || null !== $length) {
                return VmString::byteSlice($data, $offset, $length);
            }

            return $data;
        }
        if (VmPhpMemoryStream::isSupportedUri($path)) {
            $data = self::readPathContentsViaOpen($path, $ctx);
            if (false === $data) {
                return false;
            }
            if (0 !== $offset || null !== $length) {
                return VmString::byteSlice($data, $offset, $length);
            }

            return $data;
        }
        if (VmPhpFilterStream::isSupportedUri($path)) {
            $data = self::readPathContentsViaOpen($path, $ctx);
            if (false === $data) {
                return false;
            }
            if (0 !== $offset || null !== $length) {
                return VmString::byteSlice($data, $offset, $length);
            }

            return $data;
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
        if (self::isPharUri($path)) {
            $data = self::readPharUriContents($path);
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
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isSupportedUri($path)) {
            $data = self::readPathContentsViaOpen($path, $ctx);
            if (false === $data) {
                return false;
            }
            if (0 !== $offset || null !== $length) {
                return VmString::byteSlice($data, $offset, $length);
            }

            return $data;
        }
        if (VmHttpLastResponseHeaders::isHttpUrl($path)) {
            $data = VmHttpFetchNative::fetch(
                $path,
                $httpOptions,
                $ctx,
                $streamContext instanceof \PHPCompiler\VM\Variable ? $streamContext : null
            );
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
        $content = self::pathRequiresStreamOpen($path)
            ? self::readPathContentsViaOpen($path)
            : VmFsReadNative::read($path);
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
        $total = self::passthruHandleToStdout($handle, $path);
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
        if (VmPhpMemoryStream::isSupportedUri($path)
            || VmStreamWrapperRegistry::isCustomProtocol($path)
            || \PHPCompiler\ext\brotli\VmBrotliStream::isSupportedUri($path)) {
            return self::filePutContentsViaOpen($path, $data, $flags);
        }

        $written = VmFsWriteNative::write($path, $data, $flags);
        if (false !== $written) {
            // Zend keeps successful filesize/filemtime until clearstatcache (#22841).
            // Only drop a prior negative miss so create-after-stat-fail stays visible (#7436).
            VmStatCache::invalidateNegative($path);
        }

        return $written;
    }

    private static function filePutContentsViaOpen(string $path, string $data, int $flags): int|false
    {
        if (0 !== ($flags & \LOCK_EX)) {
            self::warnLockExNonRegularFile();

            return false;
        }
        $mode = (0 !== ($flags & StdlibConstants::FILE_APPEND)) ? 'ab' : 'wb';
        $handle = self::fopen($path, $mode);
        if (false === $handle) {
            return false;
        }
        $written = self::fwrite($handle, $data);
        self::fclose($handle);
        if (false === $written) {
            return false;
        }

        return $written;
    }

    /**
     * php-src ext/standard/file.c — stream_get_contents seek failure (#21986).
     */
    public static function warnStreamGetContentsSeekFailed(int $offset): void
    {
        $message = \sprintf(
            'stream_get_contents(): Failed to seek to position %d in the stream',
            $offset
        );
        $vm = VM::running();
        if (null === $vm) {
            @\trigger_error($message, \E_USER_WARNING);

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

    private static function warnLockExNonRegularFile(): void
    {
        $message = 'file_put_contents(): Exclusive locks may only be set for regular files';
        $vm = VM::running();
        if (null === $vm) {
            @\trigger_error($message, \E_USER_WARNING);

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

    public static function fopen(string $path, string $mode, ?\PHPCompiler\VM\Context $ctx = null) {
        $path = self::rewritePharInterceptPath($path);
        if (self::isPharUri($path)) {
            if (null === $ctx) {
                return false;
            }

            return self::finalizeStreamOpen(self::openPharUri($path, $mode), $mode);
        }
        if (VmStreamWrapperRegistry::isCustomProtocol($path)) {
            if (null === $ctx) {
                $vm = VM::running();
                $ctx = null !== $vm ? $vm->context : null;
            }
            if (null === $ctx) {
                return false;
            }

            return self::finalizeStreamOpen(
                VmUserStream::open($ctx->runtime->vm, $ctx, $path, $mode),
                $mode
            );
        }
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isSupportedUri($path)) {
            return self::finalizeStreamOpen(
                \PHPCompiler\ext\brotli\VmBrotliStream::open($path, $mode),
                $mode
            );
        }
        if (VmFsStdio::isStdioUri($path)) {
            return self::finalizeStreamOpen(VmFsStdio::open($path, $mode), $mode);
        }
        if (VmPhpMemoryStream::isSupportedUri($path)) {
            if (!VmPhpMemoryStream::isValidMode($mode)) {
                return self::allocateFailedStreamHandle();
            }

            return self::finalizeStreamOpen(VmPhpMemoryStream::open($path, $mode), $mode);
        }
        if (VmDataStream::isSupportedUri($path)) {
            return self::finalizeStreamOpen(VmDataStream::open($path, $mode), $mode);
        }
        if (VmPhpInputOutputStream::isSupportedUri($path)) {
            if (!VmPhpInputOutputStream::isValidMode($path, $mode)) {
                return self::allocateFailedStreamHandle();
            }

            return self::finalizeStreamOpen(VmPhpInputOutputStream::open($path, $mode), $mode);
        }
        if (VmPhpFilterStream::isSupportedUri($path)) {
            $handle = self::finalizeStreamOpen(VmPhpFilterStream::open($path, $mode, $ctx), $mode);
            if (false !== $handle) {
                self::registerStreamPath($handle, $path);
            }

            return $handle;
        }
        if (VmPhpFdStream::isFdUri($path)) {
            return self::finalizeStreamOpen(VmPhpFdStream::openFromUri($path, $mode), $mode);
        }
        if (VmHttpLastResponseHeaders::isHttpUrl($path)) {
            // Probe TCP only — HTTP stream handles not wired; match Zend connect strerror (#25288).
            VmHttpFetchPure::probeConnectFailure($path);

            return false;
        }
        if (\str_starts_with($path, 'php://')) {
            return false;
        }
        if (!VmFsOpenNative::available()) {
            return false;
        }

        return self::finalizeStreamOpen(VmFsOpenNative::open($path, $mode), $mode);
    }

    /**
     * @return int|false
     */
    private static function finalizeStreamOpen(int|false $handle, string $userMode): int|false
    {
        if (false !== $handle) {
            VmStreamContext::ensureDefaultForStreamOpen();
            self::registerStreamMode($handle, $userMode);
        }

        return $handle;
    }

    /**
     * popen() — open pipe to subprocess (php-src ext/standard/exec.c; #6211, #8244, #8951).
     * VmPopenPure SSOT via VmPopenNative (#6211, #8244, #8951, #12266).
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
            self::registerStreamMode($id, $mode);

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
            // php-src ext/standard/exec.c — non-popen stream: release handle, return 0 (#13305).
            if (self::isValidHandle($handle)) {
                self::fclose($handle);
            }

            return 0;
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
            unset(self::$handlePaths[$handle], self::$handleModes[$handle], self::$handleBlocked[$handle]);

            return;
        }
        $fp = self::detachStreamHandle($handle);
        if (\is_resource($fp)) {
            @fclose($fp);
        }
    }

    /**
     * Register a VM stream handle for bz2 stream I/O (#17301).
     *
     * @return int|false
     */
    public static function adoptBzNativePlaceholder(string $uri)
    {
        $id = VmPhpMemoryStream::open('php://memory', 'r+b');
        if (false === $id) {
            return false;
        }
        self::$handlePaths[$id] = $uri;
        self::$bzNativePlaceholders[$id] = true;

        return $id;
    }

    public static function isBzNativePlaceholder(int $handle): bool
    {
        return isset(self::$bzNativePlaceholders[$handle]);
    }

    /**
     * Register a VM stream handle for zip_open() procedural archives (#6370).
     *
     * @return int|false
     */
    public static function adoptZipArchivePlaceholder(string $path)
    {
        $id = VmPhpMemoryStream::open('php://memory', 'r+b');
        if (false === $id) {
            return false;
        }
        self::$handlePaths[$id] = 'zip://'.$path;
        self::$zipArchivePlaceholders[$id] = $path;

        return $id;
    }

    public static function isZipArchivePlaceholder(int $handle): bool
    {
        return isset(self::$zipArchivePlaceholders[$handle]);
    }

    public static function releaseZipArchivePlaceholder(int $handle): void
    {
        if (!isset(self::$zipArchivePlaceholders[$handle])) {
            return;
        }
        unset(self::$zipArchivePlaceholders[$handle]);
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            VmPhpMemoryStream::close($handle);
            unset(self::$handlePaths[$handle], self::$handleModes[$handle], self::$handleBlocked[$handle]);
        }
    }

    /**
     * @return int|false
     */
    public static function adoptZipEntryPlaceholder(int $archiveHandle)
    {
        if (!isset(self::$zipArchivePlaceholders[$archiveHandle])) {
            return false;
        }
        $id = VmPhpMemoryStream::open('php://memory', 'r+b');
        if (false === $id) {
            return false;
        }
        self::$handlePaths[$id] = 'zip-entry://'.$archiveHandle;
        self::$zipEntryPlaceholders[$id] = $archiveHandle;

        return $id;
    }

    public static function isZipEntryPlaceholder(int $handle): bool
    {
        return isset(self::$zipEntryPlaceholders[$handle]);
    }

    public static function releaseZipEntryPlaceholder(int $handle): void
    {
        if (!isset(self::$zipEntryPlaceholders[$handle])) {
            return;
        }
        unset(self::$zipEntryPlaceholders[$handle]);
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            VmPhpMemoryStream::close($handle);
            unset(self::$handlePaths[$handle], self::$handleModes[$handle], self::$handleBlocked[$handle]);
        }
    }

    public static function releaseBzNativePlaceholder(int $handle): void
    {
        if (!isset(self::$bzNativePlaceholders[$handle])) {
            return;
        }
        unset(self::$bzNativePlaceholders[$handle]);
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            VmPhpMemoryStream::close($handle);
            unset(self::$handlePaths[$handle], self::$handleModes[$handle], self::$handleBlocked[$handle]);

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
        $handle = VmTmpfileNative::open();
        if (false !== $handle) {
            self::registerStreamMode($handle, 'r+b');
        }

        return $handle;
    }

    /** Push back bytes after scanf over-read — php-src stream read buffer (#15992). */
    public static function pushbackUnread(int $handle, string $bytes): void
    {
        if ('' === $bytes) {
            return;
        }
        self::$readPushback[$handle] = (self::$readPushback[$handle] ?? '').$bytes;
    }

    private static function takeReadPushback(int $handle, int $length): string
    {
        $pending = self::$readPushback[$handle] ?? '';
        if ('' === $pending) {
            return '';
        }
        $take = min($length, \strlen($pending));
        if ($take < \strlen($pending)) {
            self::$readPushback[$handle] = \substr($pending, $take);
        } else {
            unset(self::$readPushback[$handle]);
        }

        return \substr($pending, 0, $take);
    }

    public static function fread(int $handle, int $length) {
        if ($length <= 0) {
            throw new \ValueError('fread(): Argument #2 ($length) must be greater than 0');
        }
        $fromPushback = self::takeReadPushback($handle, $length);
        if (\strlen($fromPushback) === $length) {
            return VmStreamFilterChain::applyReadFilters($handle, $fromPushback);
        }
        $length -= \strlen($fromPushback);
        if (VmUserStream::isValidHandle($handle)) {
            return self::freadMergePushback($handle, $fromPushback, VmUserStream::read($handle, $length));
        }
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
            return self::freadMergePushback(
                $handle,
                $fromPushback,
                \PHPCompiler\ext\brotli\VmBrotliStream::read($handle, $length)
            );
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return self::freadMergePushback($handle, $fromPushback, VmPhpMemoryStream::read($handle, $length));
        }
        if (\PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)) {
            return self::freadMergePushback(
                $handle,
                $fromPushback,
                \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::read($handle, $length)
            );
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return self::freadMergePushback($handle, $fromPushback, VmPhpInputOutputStream::read($handle, $length));
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return self::freadMergePushback($handle, $fromPushback, VmPhpFdStream::read($handle, $length));
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return '' !== $fromPushback ? VmStreamFilterChain::applyReadFilters($handle, $fromPushback) : false;
        }

        return self::freadMergePushback($handle, $fromPushback, @fread($fp, $length));
    }

    /** @return string|false */
    private static function freadMergePushback(int $handle, string $fromPushback, string|false $data)
    {
        if (false === $data) {
            return '' !== $fromPushback ? VmStreamFilterChain::applyReadFilters($handle, $fromPushback) : false;
        }

        return VmStreamFilterChain::applyReadFilters($handle, $fromPushback.$data);
    }

    public static function fpassthru(int $handle) {
        return self::passthruHandleToStdout($handle);
    }

    /**
     * php_stream_passthru sentinel for php://output — Zend returns -1 when bytes
     * go directly to stdout (ext/standard/streams.c; #18417).
     *
     * @param int|false $total
     *
     * @return int|false
     */
    private static function finalizePassthruResult(int $handle, ?string $path, int|false $total): int|false
    {
        if (false === $total) {
            return false;
        }
        $uri = null !== $path && '' !== $path ? $path : self::handleUri($handle);
        if ('php://output' === $uri) {
            return -1;
        }

        return $total;
    }

    /**
     * Stream remaining bytes from an open VM handle to STDOUT (php_stream_passthru parity).
     *
     * @return int|false Bytes read, or false on I/O failure
     */
    private static function passthruHandleToStdout(int $handle, ?string $path = null) {
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

        return self::finalizePassthruResult($handle, $path, $total);
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
        if (VmUserStream::isValidHandle($handle)) {
            $data = VmStreamFilterChain::applyWriteFilters($handle, $data);

            return VmUserStream::write($handle, $data, $length);
        }
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
            $data = VmStreamFilterChain::applyWriteFilters($handle, $data);

            return \PHPCompiler\ext\brotli\VmBrotliStream::write($handle, $data, $length);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            $data = VmStreamFilterChain::applyWriteFilters($handle, $data);

            return VmPhpMemoryStream::write($handle, $data, $length);
        }
        if (\PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)) {
            $data = VmStreamFilterChain::applyWriteFilters($handle, $data);

            return \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::write($handle, $data, $length);
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
        unset(self::$readPushback[$handle]);
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::close($handle);
        }
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
            unset(self::$handlePaths[$handle], self::$handleModes[$handle], self::$handleBlocked[$handle]);

            return \PHPCompiler\ext\brotli\VmBrotliStream::close($handle);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            unset(self::$handlePaths[$handle], self::$handleModes[$handle], self::$handleBlocked[$handle]);

            return VmPhpMemoryStream::close($handle);
        }
        if (\PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)) {
            unset(self::$handlePaths[$handle], self::$handleModes[$handle], self::$handleBlocked[$handle]);

            return \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::close($handle);
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
        unset(self::$handles[$handle], self::$handlePaths[$handle], self::$handleModes[$handle], self::$handleBlocked[$handle], self::$handleSocketFds[$handle]);
        if (!self::releaseHostResourceRef($fp)) {
            return true;
        }

        VmPersistentSocket::forgetResource($fp);
        $closed = @fclose($fp);
        self::releaseTmpfilePath($handle);

        return $closed;
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

    public static function flock(int $handle, int $operation, ?int &$wouldBlock = null): bool
    {
        $captureWouldBlock = \func_num_args() > 2;
        if (VmUserStream::isValidHandle($handle)) {
            $ok = VmUserStream::lock($handle, $operation);
            if ($captureWouldBlock) {
                // php-src userspace.c: TODO wouldblock — leave 0 when wrapper returns.
                $wouldBlock = 0;
            }

            return $ok;
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            $ok = VmPhpFdStream::flock($handle, $operation);
            if ($captureWouldBlock) {
                // libc flock has no wouldblock out; approximate php-src EWOULDBLOCK path.
                $wouldBlock = (!$ok && (0 !== ($operation & 4))) ? 1 : 0;
            }

            return $ok;
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            if ($captureWouldBlock) {
                $wouldBlock = 0;
            }

            return false;
        }
        if ($captureWouldBlock) {
            $wb = null;
            $ok = @\flock($fp, $operation, $wb);
            $wouldBlock = null === $wb ? 0 : (int) $wb;

            return $ok;
        }

        return @\flock($fp, $operation);
    }

    public static function feof(int $handle): bool
    {
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::feof($handle);
        }
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
            return \PHPCompiler\ext\brotli\VmBrotliStream::eof($handle);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::eof($handle);
        }
        if (\PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)) {
            return \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::eof($handle);
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
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::flush($handle);
        }
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
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::setChunkSize($handle, $chunkSize);
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
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::setWriteBuffer($handle, $buffer);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::setWriteBuffer($handle, $buffer);
        }
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
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::setReadBuffer($handle, $buffer);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::setReadBuffer($handle, $buffer);
        }
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
        $reportedMode = VmStreamMeta::userFacingMode($uri, self::handleMode($handle));
        $blocked = self::handleBlocked($handle);
        if (null !== $fp) {
            $meta = VmStreamMeta::buildMetaArray($uri, $fp, null, $reportedMode, $blocked);
        } else {
            $meta = VmStreamMeta::buildMetaArray(
                $uri,
                null,
                VmStreamMeta::eofForNativeHandle($handle),
                $reportedMode,
                $blocked
            );
        }

        return self::streamMetaArrayToHashTable($meta);
    }

    /**
     * stream_set_blocking() — php-src ext/standard/streams.c (issue #6007).
     */
    public static function streamSetBlocking(int $handle, bool $mode): bool
    {
        if (VmUserStream::isValidHandle($handle)) {
            // php-src userspace: STREAM_OPTION_BLOCKING via stream_set_option (#25999)
            if (!VmUserStream::setBlocking($handle, $mode)) {
                return false;
            }
            self::setHandleBlocked($handle, $mode);

            return true;
        }
        if (VmPhpMemoryStream::isValidHandle($handle)
            || VmPhpInputOutputStream::isValidHandle($handle)) {
            self::setHandleBlocked($handle, $mode);

            return true;
        }
        $fd = self::socketFdForHandle($handle);
        if (null !== $fd) {
            $ok = VmStreamBlockingNative::setBlocking($fd, $mode);
            if ($ok) {
                self::setHandleBlocked($handle, $mode);
                if ($mode) {
                    VmProcessProcOpenNative::resumeChildForPipeHandle($handle);
                }
            }

            return $ok;
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        $ok = VmStreamBlockingNative::setBlockingForHostResource($fp, $mode);
        if ($ok) {
            self::setHandleBlocked($handle, $mode);
            if ($mode) {
                VmProcessProcOpenNative::resumeChildForPipeHandle($handle);
            }
        }

        return $ok;
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
                return self::streamSupportsLock($handle);
            case VmStreamSupports::STREAM_META_SEEKABLE:
            case VmStreamSupports::STREAM_FILTER:
                return self::streamSupportsSeekable($handle);
            case VmStreamSupports::STREAM_SUPPORT_TELL:
                return self::streamSupportsTell($handle);
            case VmStreamSupports::STREAM_SUPPORT_READ:
                return self::streamSupportsRead($handle);
            case VmStreamSupports::STREAM_SUPPORT_WRITE:
                return self::streamSupportsWrite($handle);
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

    /** stream_supports_lock() / STREAM_LOCK — php-src ext/standard/streams.c (#6039, #19462). */
    private static function streamSupportsLock(int $handle): bool
    {
        if (!self::isValidHandle($handle)) {
            return false;
        }
        if (VmPhpMemoryStream::isValidHandle($handle)
            || VmPhpInputOutputStream::isValidHandle($handle)) {
            return false;
        }
        if (VmPhpFdStream::isValidHandle($handle)) {
            return true;
        }
        $uri = self::handleUri($handle);
        if (VmStreamMeta::supportsLock($uri, self::handleMode($handle))) {
            return true;
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            return false;
        }
        if (\function_exists('stream_supports_lock')) {
            return \stream_supports_lock($fp);
        }
        // Nested JIT/AOT helpers: adopted host FILE* streams are flockable (php-src stdio).
        $adoptedUri = self::handleUri($handle);

        return '' === $adoptedUri || VmStreamMeta::supportsLock($adoptedUri, self::handleMode($handle));
    }

    private static function streamSupportsSeekable(int $handle): bool
    {
        return VmStreamMeta::supportsSeekable(self::handleUri($handle));
    }

    private static function streamSupportsTell(int $handle): bool
    {
        return VmStreamMeta::supportsTell(self::handleUri($handle));
    }

    private static function streamSupportsRead(int $handle): bool
    {
        if (!self::isValidHandle($handle)) {
            return false;
        }

        return VmStreamMeta::supportsRead(
            self::handleUri($handle),
            self::handleMode($handle) ?? 'rb'
        );
    }

    private static function streamSupportsWrite(int $handle): bool
    {
        if (!self::isValidHandle($handle)) {
            return false;
        }

        return VmStreamMeta::supportsWrite(
            self::handleUri($handle),
            self::handleMode($handle) ?? 'wb'
        );
    }

    private static function streamSupportsMetadata(int $handle): bool
    {
        return VmStreamMeta::supportsMetadata(self::handleUri($handle));
    }

    /** Host PHP stream resource for adopted handles, or null for VM-native streams. */
    public static function hostStreamResource(int $handle): mixed
    {
        return self::lookup($handle);
    }

    /**
     * stream_socket_enable_crypto() — php-src ext/standard/streamsfuncs.c (#4610).
     */
    public static function streamSocketEnableCrypto(
        int $handle,
        bool $enable,
        ?int $cryptoMethod = null,
        ?int $sessionHandle = null,
        ?string $capturePeerCert = null,
        ?string $passphrase = null
    ): bool {
        return VmStreamEnableCrypto::invoke(
            $handle,
            $enable,
            $cryptoMethod,
            $sessionHandle,
            $capturePeerCert,
            $passphrase
        );
    }

    /**
     * stream_set_timeout() — php-src ext/standard/streams.c (issue #3754, #25924).
     *
     * php-src returns the result of php_stream_set_option(READ_TIMEOUT): false for
     * memory/temp/plainfile (option unsupported), true only when the transport accepts it.
     * User wrappers: STREAM_OPTION_READ_TIMEOUT via stream_set_option (#25996).
     */
    public static function streamSetTimeout(int $handle, int $seconds, int $microseconds = 0): bool
    {
        if (VmUserStream::isValidHandle($handle)) {
            // php-src: PHP_STREAM_OPTION_READ_TIMEOUT = 4; arg1=sec, arg2=usec
            return VmUserStream::setOption($handle, 4, $seconds, $microseconds);
        }
        $fp = self::lookup($handle);
        if (null === $fp) {
            // Native memory/temp/fd handles have no host FILE* — same as unsupported option.
            return false;
        }

        return (bool) @\stream_set_timeout($fp, $seconds, $microseconds);
    }

    public static function ftruncate(int $handle, int $size): bool
    {
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::truncate($handle, $size);
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return VmPhpMemoryStream::truncate($handle, $size);
        }
        if (VmPhpInputOutputStream::isValidHandle($handle)) {
            return false;
        }
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
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
            return \PHPCompiler\ext\brotli\VmBrotliStream::tell($handle);
        }
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::tell($handle);
        }
        if (\PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)) {
            return \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::tell($handle);
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
        if (VmUserStream::isValidHandle($handle)) {
            $byte = VmUserStream::read($handle, 1);
        } elseif (\PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
            $byte = \PHPCompiler\ext\brotli\VmBrotliStream::read($handle, 1);
        } elseif (VmPhpMemoryStream::isValidHandle($handle)) {
            $byte = VmPhpMemoryStream::read($handle, 1);
        } elseif (VmPhpInputOutputStream::isValidHandle($handle)) {
            $byte = VmPhpInputOutputStream::read($handle, 1);
        } elseif (VmPhpFdStream::isValidHandle($handle)) {
            $byte = VmPhpFdStream::read($handle, 1);
        } else {
            $fp = self::lookup($handle);
            if (null === $fp) {
                return false;
            }
            $byte = @\fgetc($fp);
        }
        if (false === $byte || '' === $byte) {
            return false;
        }

        return VmStreamFilterChain::applyReadFilters($handle, $byte);
    }

    public static function fgets(int $handle, ?int $length = null) {
        if (VmUserStream::isValidHandle($handle)) {
            $line = VmUserStream::fgets($handle, $length);
        } elseif (VmPhpMemoryStream::isValidHandle($handle)) {
            $line = VmPhpMemoryStream::fgets($handle, $length);
        } elseif (VmPhpFdStream::isValidHandle($handle)) {
            $line = VmPhpFdStream::fgets($handle, $length);
        } else {
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
        }
        if (false === $line) {
            return false;
        }

        return VmStreamFilterChain::applyReadFilters($handle, $line);
    }

    /**
     * php-src ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_get_line).
     */
    public static function streamGetLine(int $handle, int $maxLength, ?string $ending = null) {
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::streamGetLine($handle, $maxLength, $ending);
        }
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
        string $eol = "\n",
    ) {
        if (self::isNativeCsvStreamHandle($handle)) {
            $line = VmCsv::formatLine($fields, $separator, $enclosure, $escape).$eol;
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
        // php-src 8.1+: php_fputcsv(..., eol) — pass through when host supports it (#19368).
        $written = @\fputcsv($fp, $fields, $separator, $enclosure, $escape, $eol);
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
            || VmPhpFdStream::isValidHandle($handle)
            || VmUserStream::isValidHandle($handle);
    }

    /**
     * fgetcsv() on native VM / userspace streams via fgets + VmCsv::parseLine
     * (#5243, #26004, StringFgetcsvJit).
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
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
            return \PHPCompiler\ext\brotli\VmBrotliStream::seek($handle, $offset, $whence);
        }
        if (VmUserStream::isValidHandle($handle)) {
            return VmUserStream::seek($handle, $offset, $whence);
        }
        if (\PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)) {
            return \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::seek($handle, $offset, $whence);
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
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
            return 0 === \PHPCompiler\ext\brotli\VmBrotliStream::seek($handle, 0, \SEEK_SET);
        }
        if (VmUserStream::isValidHandle($handle)) {
            return 0 === VmUserStream::seek($handle, 0, \SEEK_SET);
        }
        if (\PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)) {
            return 0 === \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::seek($handle, 0, \SEEK_SET);
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
        if (VmUserStream::isValidHandle($handle)) {
            $data = VmUserStream::streamGetContents($handle, $maxlength, $offset);
            if (false === $data) {
                return false;
            }

            return VmStreamFilterChain::applyReadFilters($handle, $data);
        }
        if (\PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
            $data = \PHPCompiler\ext\brotli\VmBrotliStream::streamGetContents($handle, $maxlength, $offset);
            if (false === $data) {
                return false;
            }

            return VmStreamFilterChain::applyReadFilters($handle, $data);
        }
        if (\PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)) {
            $data = \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::streamGetContents($handle, $maxlength, $offset);
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
        // php-src file.c: only offset >= 0 seeks; negative (incl. < -1) keeps current pos (#23190).
        if ($offset >= 0 && 0 !== @\fseek($fp, $offset, \SEEK_SET)) {
            self::warnStreamGetContentsSeekFailed($offset);

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
     * get_resource_type() for fopen() stream handles (#3142).
     */
    public static function getResourceType(int $handle): ?string
    {
        if (isset(self::$handles[$handle]) || VmPhpMemoryStream::isValidHandle($handle) || \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle) || VmPhpInputOutputStream::isValidHandle($handle) || VmPhpFdStream::isValidHandle($handle) || \PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle)) {
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
        if (isset(self::$bzNativePlaceholders[$handle])) {
            return 'bzip2';
        }
        if (isset(self::$zipArchivePlaceholders[$handle])) {
            return 'Zip Archive';
        }
        if (isset(self::$zipEntryPlaceholders[$handle])) {
            return 'Zip Entry';
        }
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            return 'stream';
        }
        if (\PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)) {
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
        if (self::isFailedStreamHandle($handle)) {
            return false;
        }

        return isset(self::$handles[$handle])
            || VmUserStream::isValidHandle($handle)
            || VmPhpMemoryStream::isValidHandle($handle)
            || \PHPCompiler\ext\sqlite3\VmSqlite3BlobStream::isValidHandle($handle)
            || VmPhpInputOutputStream::isValidHandle($handle)
            || VmPhpFdStream::isValidHandle($handle)
            || \PHPCompiler\ext\brotli\VmBrotliStream::isValidHandle($handle);
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
        if (null === $type) {
            foreach (VmStreamContext::activeContextVariables() as $contextVar) {
                $value = new Variable();
                $value->copyFrom($contextVar);
                $ht->addIndex($index, $value);
                ++$index;
            }
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
        unset(
            self::$handles[$handle],
            self::$handlePaths[$handle],
            self::$handleModes[$handle],
            self::$handleBlocked[$handle],
            self::$handleSocketFds[$handle],
            self::$popenHandles[$handle]
        );
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
        $registered = self::$handlePaths[$handle] ?? '';
        if ('' !== $registered) {
            return $registered;
        }
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

        return '';
    }

    /** Record fopen URI for JIT/AOT stream handles ({@see StreamPathJitHelper}, #9480). */
    public static function registerStreamPath(int $handle, string $path): void
    {
        if ($handle > 0 && '' !== $path) {
            self::$handlePaths[$handle] = $path;
        }
    }

    /**
     * tmpfile() — defer unlink until fclose so meta uri stays path-visible (#24786).
     */
    public static function registerTmpfileUnlinkOnClose(int $handle, string $path): void
    {
        if ($handle > 0 && '' !== $path) {
            self::$tmpfileUnlinkPaths[$handle] = $path;
        }
    }

    private static function releaseTmpfilePath(int $handle): void
    {
        $path = self::$tmpfileUnlinkPaths[$handle] ?? null;
        if (null === $path) {
            return;
        }
        unset(self::$tmpfileUnlinkPaths[$handle]);
        VmFsUnlink::unlink($path);
    }

    public static function clearStreamPath(int $handle): void
    {
        unset(self::$handlePaths[$handle]);
    }

    /** Record user fopen mode for stream_get_meta_data() ({@see StreamModeJitHelper}, #13021). */
    public static function registerStreamMode(int $handle, string $mode): void
    {
        if ($handle > 0 && '' !== $mode) {
            self::$handleModes[$handle] = $mode;
        }
    }

    public static function clearStreamMode(int $handle): void
    {
        unset(self::$handleModes[$handle]);
    }

    public static function handleMode(int $handle): ?string
    {
        return self::$handleModes[$handle] ?? null;
    }

    public static function setHandleBlocked(int $handle, bool $blocked): void
    {
        if ($handle > 0) {
            self::$handleBlocked[$handle] = $blocked;
        }
    }

    public static function handleBlocked(int $handle): bool
    {
        if (VmPhpMemoryStream::isValidHandle($handle)) {
            $uri = VmPhpMemoryStream::uriForHandle($handle);
            // php-src php_stream_memory: blocking flag stays true regardless of stream_set_blocking (#17928).
            if ('php://memory' === $uri || \str_starts_with($uri, 'php://fd/')) {
                return true;
            }
        }

        return self::$handleBlocked[$handle] ?? true;
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
        if (null === $path) {
            return false;
        }

        return VmFsDiskNative::diskFreeSpace($path);
    }

    /**
     * disk_total_space() / disktotalspace() — total bytes on filesystem (php-src filestat.c, #3758).
     *
     * @return float|false
     */
    public static function diskTotalSpace(?string $path)
    {
        if (null === $path) {
            return false;
        }

        return VmFsDiskNative::diskTotalSpace($path);
    }

    private static function rewritePharInterceptPath(string $path): string
    {
        if (!\class_exists(\PHPCompiler\ext\phar\VmPharStream::class, false)) {
            return $path;
        }

        return \PHPCompiler\ext\phar\VmPharStream::rewriteInterceptedPath($path);
    }

    private static function isPharUri(string $path): bool
    {
        return \str_starts_with($path, 'phar://');
    }

    /**
     * @return string|false
     */
    private static function readPharUriContents(string $uri)
    {
        if (!\class_exists(\PHPCompiler\ext\phar\VmPharStream::class)) {
            return false;
        }

        return \PHPCompiler\ext\phar\VmPharStream::readContents($uri);
    }

    /**
     * @return int|false
     */
    private static function openPharUri(string $uri, string $mode)
    {
        if (!\class_exists(\PHPCompiler\ext\phar\VmPharStream::class)) {
            return false;
        }

        return \PHPCompiler\ext\phar\VmPharStream::open($uri, $mode);
    }
}
