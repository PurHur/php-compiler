<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\ObjectRegistry;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStatPath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\standard\VmZlib;

/**
 * Phar executable archive state — php-src ext/phar/phar_object.c (#20628).
 *
 * On-disk layout for this build: stub ending in `__HALT_COMPILER(); ?>` + ustar payload
 * (same tar writer as PharData). Round-trips inside this VM; respects phar.readonly.
 */
final class VmPharArchive
{
    public const CLASS_LC = 'phar';

    private const HALT = "__HALT_COMPILER(); ?>";

    /** Reserved ustar member for archive metadata (#21229). */
    private const META_ENTRY = '.phar/metadata';

    /** Reserved ustar member for per-entry metadata map (#21651). */
    private const ENTRY_META = '.phar/entrymeta';

    /** Reserved ustar member for per-entry perms/flags (#21652). */
    private const ENTRY_ATTRS = '.phar/entryattrs';

    /**
     * @var array<int, array{
     *   path: string,
     *   files: array<string, string>,
     *   dirs: array<string, true>,
     *   dirty: bool,
     *   buffering: bool,
     *   stub: string,
     *   alias: string,
     *   hasMetadata: bool,
     *   metadata: mixed,
     *   fileMetadata: array<string, mixed>,
     *   fileAttrs: array<string, array{perms: int, flags: int}>,
     *   wholeCompression: int
     *   sigFlags: int
     *   signature: string
     *   sigPrivateKey: ?string
     * }>
     */
    private static array $state = [];

    /** @var array<string, string> alias → archive path (Phar::loadPhar; #21232). */
    private static array $aliases = [];

    /** @var array<string, true> full archive paths (php-src PHAR_G phar_fname_map; #21327). */
    private static array $filenameMap = [];

    public static function bind(
        ObjectEntry $object,
        string $path,
        array $files,
        bool $dirty,
        array $dirs = [],
        string $stub = '',
        string $alias = '',
        bool $buffering = false,
        bool $hasMetadata = false,
        mixed $metadata = null,
        int $wholeCompression = VmPhar::COMPRESSED_NONE,
        int $sigFlags = 0,
        string $signature = '',
        ?string $sigPrivateKey = null,
        array $fileMetadata = [],
        array $fileAttrs = []
    ): void {
        if ('' === $stub) {
            $stub = self::createDefaultStub();
        }
        self::$state[$object->id] = [
            'path' => $path,
            'files' => $files,
            'dirs' => $dirs,
            'dirty' => $dirty,
            'buffering' => $buffering,
            'stub' => $stub,
            'alias' => $alias,
            'hasMetadata' => $hasMetadata,
            'metadata' => $metadata,
            'fileMetadata' => $fileMetadata,
            'fileAttrs' => $fileAttrs,
            'wholeCompression' => $wholeCompression,
            'sigFlags' => $sigFlags,
            'signature' => $signature,
            'sigPrivateKey' => $sigPrivateKey,
        ];
        self::$objectsByPath[$path] = $object;
    }

    public static function open(ObjectEntry $object, string $path): void
    {
        $path = \str_replace('\\', '/', $path);
        if (VmStatPath::isFile($path)) {
            $binary = VmFs::fileGetContents($path);
            if (false === $binary) {
                throw new \UnexpectedValueException('phar error: unable to open phar "'.$path.'"');
            }
            $wholeCompression = VmPhar::COMPRESSED_NONE;
            if (\strlen($binary) >= 2 && "\x1f" === $binary[0] && "\x8b" === $binary[1]) {
                $decoded = VmZlib::gzdecode($binary);
                if (false === $decoded) {
                    throw new \UnexpectedValueException('phar error: unable to open phar "'.$path.'"');
                }
                $binary = $decoded;
                $wholeCompression = VmPhar::COMPRESSED_GZ;
            }
            [$stub, $payload] = self::splitStub($binary);
            $entries = VmPharTar::readArchiveEntries($payload);
            $hasMetadata = false;
            $metadata = null;
            if (isset($entries['files'][self::META_ENTRY])) {
                $hasMetadata = true;
                $raw = $entries['files'][self::META_ENTRY];
                $metadata = '' === $raw ? null : \unserialize($raw);
                unset($entries['files'][self::META_ENTRY]);
            }
            $fileMetadata = [];
            if (isset($entries['files'][self::ENTRY_META])) {
                $raw = $entries['files'][self::ENTRY_META];
                $decoded = '' === $raw ? [] : \unserialize($raw);
                $fileMetadata = \is_array($decoded) ? $decoded : [];
                unset($entries['files'][self::ENTRY_META]);
            }
            $fileAttrs = [];
            if (isset($entries['files'][self::ENTRY_ATTRS])) {
                $raw = $entries['files'][self::ENTRY_ATTRS];
                $decoded = '' === $raw ? [] : \unserialize($raw);
                $fileAttrs = \is_array($decoded) ? $decoded : [];
                unset($entries['files'][self::ENTRY_ATTRS]);
            }
            self::bind(
                $object,
                $path,
                $entries['files'],
                false,
                $entries['dirs'],
                $stub,
                '',
                false,
                $hasMetadata,
                $metadata,
                $wholeCompression,
                0,
                '',
                null,
                $fileMetadata,
                $fileAttrs
            );
            self::registerFilenameMap($path);

            return;
        }
        $dir = \dirname($path);
        if ('.' !== $dir && !VmStatPath::isDir($dir) && !VmStatPath::isFile($dir)) {
            throw new \UnexpectedValueException('phar error: unable to create phar "'.$path.'"');
        }
        self::bind($object, $path, [], true, []);
        self::registerFilenameMap($path);
        // New archive is dirty; flush immediately only when writable.
        if (VmPhar::canWrite()) {
            self::flush($object);
        }
    }

    public static function requireWritable(string $method): void
    {
        if (!VmPhar::canWrite()) {
            throw new \UnexpectedValueException(
                'Cannot write to archive - write operations disabled by the php.ini setting phar.readonly'
            );
        }
    }

    public static function addFromString(ObjectEntry $object, string $localname, string $contents): void
    {
        self::requireWritable('Phar::addFromString');
        self::requireState($object);
        $localname = \ltrim(\str_replace('\\', '/', $localname), '/');
        if ('' === $localname) {
            throw new \UnexpectedValueException('Entry name cannot be empty');
        }
        self::$state[$object->id]['files'][$localname] = $contents;
        unset(self::$state[$object->id]['dirs'][$localname]);
        self::markDirty($object);
    }

    public static function addEmptyDir(ObjectEntry $object, string $dirname): void
    {
        self::requireWritable('Phar::addEmptyDir');
        $dirname = \rtrim(\ltrim(\str_replace('\\', '/', $dirname), '/'), '/');
        if ('' === $dirname) {
            throw new \UnexpectedValueException('Directory name cannot be empty');
        }
        self::requireState($object);
        self::$state[$object->id]['dirs'][$dirname] = true;
        unset(self::$state[$object->id]['files'][$dirname]);
        self::markDirty($object);
    }

    public static function addFile(ObjectEntry $object, string $filename, ?string $localname = null): void
    {
        self::requireWritable('Phar::addFile');
        $filename = \str_replace('\\', '/', $filename);
        if (!VmStatPath::isFile($filename)) {
            throw new \UnexpectedValueException('Cannot open "'.$filename.'" for reading');
        }
        $contents = VmFs::fileGetContents($filename);
        if (false === $contents) {
            throw new \UnexpectedValueException('Cannot open "'.$filename.'" for reading');
        }
        if (null === $localname || '' === $localname) {
            $localname = VmString::basename($filename);
        }
        self::addFromString($object, $localname, $contents);
    }

    /** @return array<string, string> */
    public static function buildFromDirectory(ObjectEntry $object, string $directory, ?string $pattern = null): array
    {
        self::requireWritable('Phar::buildFromDirectory');
        $directory = \rtrim(\str_replace('\\', '/', $directory), '/');
        if (!VmStatPath::isDir($directory)) {
            throw new \UnexpectedValueException('Directory "'.$directory.'" does not exist');
        }
        $map = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $full = \str_replace('\\', '/', $fileInfo->getPathname());
            $local = \ltrim(\substr($full, \strlen($directory)), '/');
            if (null !== $pattern && '' !== $pattern && 1 !== \preg_match($pattern, $local)) {
                continue;
            }
            $contents = VmFs::fileGetContents($full);
            if (false === $contents) {
                continue;
            }
            self::addFromString($object, $local, $contents);
            $map[$local] = $full;
        }

        return $map;
    }

    /** @param array<int|string, string> $pathMap @return array<string, string> */
    public static function buildFromPathMap(ObjectEntry $object, array $pathMap, ?string $baseDirectory = null): array
    {
        self::requireWritable('Phar::buildFromIterator');
        $baseDirectory = null === $baseDirectory ? null : \rtrim(\str_replace('\\', '/', $baseDirectory), '/');
        $map = [];
        foreach ($pathMap as $key => $pathname) {
            $pathname = \str_replace('\\', '/', (string) $pathname);
            if (!VmStatPath::isFile($pathname)) {
                continue;
            }
            if (\is_string($key) && !\ctype_digit((string) $key)) {
                $local = \ltrim(\str_replace('\\', '/', $key), '/');
            } elseif (null !== $baseDirectory && \str_starts_with($pathname, $baseDirectory.'/')) {
                $local = \substr($pathname, \strlen($baseDirectory) + 1);
            } else {
                $local = VmString::basename($pathname);
            }
            $contents = VmFs::fileGetContents($pathname);
            if (false === $contents) {
                continue;
            }
            self::addFromString($object, $local, $contents);
            $map[$local] = $pathname;
        }

        return $map;
    }

    public static function extractTo(ObjectEntry $object, string $directory): bool
    {
        $st = self::requireState($object);
        $directory = \rtrim(\str_replace('\\', '/', $directory), '/');
        if (!VmStatPath::isDir($directory) && !VmFs::mkdir($directory, 0777, true) && !VmStatPath::isDir($directory)) {
            return false;
        }
        foreach ($st['dirs'] as $name => $_) {
            $target = $directory.'/'.$name;
            if (!VmStatPath::isDir($target)) {
                VmFs::mkdir($target, 0777, true);
            }
        }
        foreach ($st['files'] as $name => $contents) {
            $target = $directory.'/'.$name;
            $parent = \dirname($target);
            if (!VmStatPath::isDir($parent)) {
                VmFs::mkdir($parent, 0777, true);
            }
            if (false === VmFs::filePutContents($target, $contents)) {
                return false;
            }
        }

        return true;
    }

    public static function setStub(ObjectEntry $object, string $stub): bool
    {
        self::requireWritable('Phar::setStub');
        self::requireState($object);
        if (!\str_contains($stub, self::HALT) && !\str_contains($stub, '__HALT_COMPILER()')) {
            throw new \UnexpectedValueException('illegal stub for an executable phar archive');
        }
        self::$state[$object->id]['stub'] = $stub;
        self::markDirty($object);

        return true;
    }

    public static function getStub(ObjectEntry $object): string
    {
        return self::requireState($object)['stub'];
    }

    public static function setAlias(ObjectEntry $object, string $alias): bool
    {
        self::requireWritable('Phar::setAlias');
        self::requireState($object);
        self::$state[$object->id]['alias'] = $alias;
        self::markDirty($object);

        return true;
    }

    public static function getAlias(ObjectEntry $object): string|false
    {
        $alias = self::requireState($object)['alias'];

        return '' === $alias ? false : $alias;
    }

    public static function startBuffering(ObjectEntry $object): void
    {
        self::requireWritable('Phar::startBuffering');
        self::requireState($object);
        self::$state[$object->id]['buffering'] = true;
    }

    public static function stopBuffering(ObjectEntry $object): void
    {
        self::requireWritable('Phar::stopBuffering');
        self::requireState($object);
        self::$state[$object->id]['buffering'] = false;
        if (self::$state[$object->id]['dirty']) {
            self::flush($object);
        }
    }

    /** php-src zim_Phar_isBuffering (#21228). */
    public static function isBuffering(ObjectEntry $object): bool
    {
        return self::requireState($object)['buffering'];
    }

    /**
     * php-src zim_Phar_count — file + directory entries (#21228).
     */
    public static function count(ObjectEntry $object): int
    {
        $st = self::requireState($object);

        return \count($st['files']) + \count($st['dirs']);
    }

    /**
     * php-src zim_Phar_delete — remove entry; BadMethodCallException if missing (#21228).
     */
    public static function delete(ObjectEntry $object, string $localname): bool
    {
        self::requireWritable('Phar::delete');
        self::requireState($object);
        $localname = \rtrim(\ltrim(\str_replace('\\', '/', $localname), '/'), '/');
        if (!isset(self::$state[$object->id]['files'][$localname])
            && !isset(self::$state[$object->id]['dirs'][$localname])) {
            throw new \BadMethodCallException('Entry '.$localname.' does not exist and cannot be deleted');
        }
        unset(self::$state[$object->id]['files'][$localname], self::$state[$object->id]['dirs'][$localname]);
        unset(self::$state[$object->id]['fileMetadata'][$localname]);
        unset(self::$state[$object->id]['fileAttrs'][$localname]);
        self::markDirty($object);

        return true;
    }

    /** php-src zim_Phar_hasMetadata (#21229). */
    public static function hasMetadata(ObjectEntry $object): bool
    {
        return self::requireState($object)['hasMetadata'];
    }

    /** php-src zim_Phar_getMetadata (#21229). */
    public static function getMetadata(ObjectEntry $object): mixed
    {
        $st = self::requireState($object);

        return $st['hasMetadata'] ? $st['metadata'] : null;
    }

    /** php-src zim_Phar_setMetadata (#21229). */
    public static function setMetadata(ObjectEntry $object, mixed $metadata): void
    {
        self::requireWritable('Phar::setMetadata');
        self::requireState($object);
        self::$state[$object->id]['hasMetadata'] = true;
        self::$state[$object->id]['metadata'] = $metadata;
        self::markDirty($object);
    }

    /** php-src zim_Phar_delMetadata (#21229). */
    public static function delMetadata(ObjectEntry $object): bool
    {
        self::requireWritable('Phar::delMetadata');
        self::requireState($object);
        self::$state[$object->id]['hasMetadata'] = false;
        self::$state[$object->id]['metadata'] = null;
        self::markDirty($object);

        return true;
    }

    /** @var array<string, ObjectEntry> path → live Phar object (#21651). */
    private static array $objectsByPath = [];

    /**
     * Look up live archive ObjectEntry by on-disk path (PharFileInfo entry metadata; #21651).
     */
    public static function objectByPath(string $archivePath): ?ObjectEntry
    {
        $archivePath = \str_replace('\\', '/', $archivePath);

        return self::$objectsByPath[$archivePath] ?? null;
    }

    /** @return array{has: bool, value: mixed} */
    public static function getEntryMetadata(string $archivePath, string $localname): array
    {
        $object = self::objectByPath($archivePath);
        if (null === $object || !isset(self::$state[$object->id])) {
            return ['has' => false, 'value' => null];
        }
        $map = self::$state[$object->id]['fileMetadata'] ?? [];
        if (!\array_key_exists($localname, $map)) {
            return ['has' => false, 'value' => null];
        }

        return ['has' => true, 'value' => $map[$localname]];
    }

    public static function setEntryMetadata(string $archivePath, string $localname, mixed $metadata): void
    {
        if (!VmPhar::canWrite()) {
            throw new \BadMethodCallException(
                'Write operations disabled by the php.ini setting phar.readonly'
            );
        }
        $object = self::objectByPath($archivePath);
        if (null === $object || !isset(self::$state[$object->id])) {
            throw new \BadMethodCallException('Cannot set file metadata, phar archive is not open');
        }
        if (!isset(self::$state[$object->id]['fileMetadata'])) {
            self::$state[$object->id]['fileMetadata'] = [];
        }
        self::$state[$object->id]['fileMetadata'][$localname] = $metadata;
        self::markDirty($object);
    }

    public static function delEntryMetadata(string $archivePath, string $localname): bool
    {
        if (!VmPhar::canWrite()) {
            throw new \BadMethodCallException(
                'Write operations disabled by the php.ini setting phar.readonly'
            );
        }
        $object = self::objectByPath($archivePath);
        if (null === $object || !isset(self::$state[$object->id])) {
            return true;
        }
        if (isset(self::$state[$object->id]['fileMetadata'][$localname])) {
            unset(self::$state[$object->id]['fileMetadata'][$localname]);
            self::markDirty($object);
        }

        return true;
    }

    /** @return array{perms: int, flags: int} */
    public static function getEntryAttrs(string $archivePath, string $localname): array
    {
        $object = self::objectByPath($archivePath);
        if (null === $object || !isset(self::$state[$object->id])) {
            return ['perms' => 0644, 'flags' => 0];
        }
        $attrs = self::$state[$object->id]['fileAttrs'][$localname] ?? null;
        if (!\is_array($attrs)) {
            return ['perms' => 0100644, 'flags' => 0];
        }

        return [
            'perms' => (int) ($attrs['perms'] ?? 0100644),
            'flags' => (int) ($attrs['flags'] ?? 0),
        ];
    }

    public static function setEntryAttrs(string $archivePath, string $localname, int $perms, int $flags): void
    {
        if (!VmPhar::canWrite()) {
            throw new \BadMethodCallException(
                'Write operations disabled by the php.ini setting phar.readonly'
            );
        }
        $object = self::objectByPath($archivePath);
        if (null === $object || !isset(self::$state[$object->id])) {
            throw new \BadMethodCallException('Cannot set file attributes, phar archive is not open');
        }
        if (!isset(self::$state[$object->id]['fileAttrs'])) {
            self::$state[$object->id]['fileAttrs'] = [];
        }
        self::$state[$object->id]['fileAttrs'][$localname] = [
            'perms' => $perms,
            'flags' => $flags,
        ];
        self::markDirty($object);
    }

    /** php-src zim_Phar_getVersion — archive API version string (#21230). */
    public static function getVersion(ObjectEntry $object): string
    {
        self::requireState($object);

        return VmPhar::API_VERSION;
    }

    /**
     * php-src zim_Phar_isWritable — !readonly (phar_object.c; #21230).
     * Matches Phar::canWrite() for this VM subset (FS writability deferred).
     */
    public static function isWritable(ObjectEntry $object): bool
    {
        self::requireState($object);

        return VmPhar::canWrite();
    }

    /**
     * php-src zim_Phar_getModified — archive has unflushed changes (#21230).
     */
    public static function getModified(ObjectEntry $object): bool
    {
        return self::requireState($object)['dirty'];
    }

    public static function compressFiles(ObjectEntry $object, int $compression): void
    {
        self::requireWritable('Phar::compressFiles');
        self::requireState($object);
        if (VmPhar::COMPRESSED_NONE === $compression) {
            self::markDirty($object);

            return;
        }
        if (!VmPhar::canCompress($compression)) {
            throw new \BadMethodCallException('Compression method not supported or unavailable');
        }
        // Tar-backed Phar stores uncompressed payloads; mark dirty so archive is rewritten.
        self::markDirty($object);
    }

    /** php-src zim_Phar_decompressFiles — tar subset marks archive dirty (#21231). */
    public static function decompressFiles(ObjectEntry $object): bool
    {
        self::requireWritable('Phar::decompressFiles');
        self::requireState($object);
        self::markDirty($object);

        return true;
    }

    /**
     * php-src zim_Phar_compress — whole-archive gzip wrapper (#21328).
     * This build supports GZ only (same subset as PharData::compress).
     */
    public static function compress(ObjectEntry $object, Context $ctx, int $compression, ?string $extension = null): ObjectEntry
    {
        self::requireWritable('Phar::compress');
        $st = self::requireState($object);
        self::ensureFlushed($object);
        if (VmPhar::COMPRESSED_GZ !== $compression) {
            throw new \BadMethodCallException('Only gzip compression is supported for Phar in this build');
        }
        if (!VmPhar::canCompress(VmPhar::COMPRESSED_GZ)) {
            throw new \BadMethodCallException('zlib extension is required for gzip compression');
        }
        $ext = null !== $extension && '' !== $extension ? \ltrim($extension, '.') : 'gz';
        $path = $st['path'];
        $outPath = \str_ends_with(\strtolower($path), '.'.$ext) ? $path : $path.'.'.$ext;
        $binary = self::buildBinary($object);
        $encoded = VmZlib::gzencode($binary);
        if (false === $encoded || false === VmFs::filePutContents($outPath, $encoded)) {
            throw new \UnexpectedValueException('phar error: unable to write phar "'.$outPath.'"');
        }
        $out = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $out->constructed = true;
        self::bind(
            $out,
            $outPath,
            $st['files'],
            false,
            $st['dirs'],
            $st['stub'],
            $st['alias'],
            false,
            $st['hasMetadata'],
            $st['metadata'],
            VmPhar::COMPRESSED_GZ,
            0,
            '',
            null,
            $st['fileMetadata'] ?? [],
            $st['fileAttrs'] ?? []
        );

        return $out;
    }

    /**
     * php-src zim_Phar_decompress — strip whole-archive compression (#21328).
     */
    public static function decompress(ObjectEntry $object, Context $ctx, ?string $extension = null): ObjectEntry
    {
        self::requireWritable('Phar::decompress');
        $st = self::requireState($object);
        self::ensureFlushed($object);
        $path = $st['path'];
        $outPath = $path;
        foreach (['.phar.gz', '.phar.bz2', '.gz', '.bz2'] as $suffix) {
            if (\str_ends_with(\strtolower($path), $suffix)) {
                $outPath = \substr($path, 0, -\strlen($suffix));
                if (\str_starts_with($suffix, '.phar.')) {
                    $outPath .= '.phar';
                }
                break;
            }
        }
        if (null !== $extension && '' !== $extension) {
            $outPath = \preg_replace('/\.[^.]+$/', '', $outPath).'.'.\ltrim($extension, '.');
        }
        $binary = self::buildBinary($object);
        if (false === VmFs::filePutContents($outPath, $binary)) {
            throw new \UnexpectedValueException('phar error: unable to write phar "'.$outPath.'"');
        }
        $out = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $out->constructed = true;
        self::bind(
            $out,
            $outPath,
            $st['files'],
            false,
            $st['dirs'],
            $st['stub'],
            $st['alias'],
            false,
            $st['hasMetadata'],
            $st['metadata'],
            VmPhar::COMPRESSED_NONE,
            0,
            '',
            null,
            $st['fileMetadata'] ?? [],
            $st['fileAttrs'] ?? []
        );

        return $out;
    }

    /**
     * php-src zim_Phar_convertToData — emit PharData tar archive (#21328).
     */
    public static function convertToData(ObjectEntry $object, Context $ctx, ?int $format = null, ?int $compression = null, ?string $extension = null): ObjectEntry
    {
        self::requireWritable('Phar::convertToData');
        $st = self::requireState($object);
        self::ensureFlushed($object);
        if (null !== $format && VmPhar::FORMAT_TAR !== $format && 0 !== $format) {
            throw new \BadMethodCallException('Only tar format is supported for Phar::convertToData() in this build');
        }
        if (null !== $compression && VmPhar::COMPRESSED_NONE !== $compression && 0 !== $compression) {
            throw new \BadMethodCallException('Only uncompressed tar is supported for Phar::convertToData() in this build');
        }
        $path = $st['path'];
        $base = \preg_replace('/\.phar(\.(gz|bz2))?$/i', '', $path) ?? $path;
        $ext = null !== $extension && '' !== $extension ? \ltrim($extension, '.') : 'tar';
        $outPath = $base.'.'.$ext;
        $binary = VmPharTar::writeArchive($st['files'], $st['dirs']);
        if (false === VmFs::filePutContents($outPath, $binary)) {
            throw new \UnexpectedValueException('phar error: unable to write phar "'.$outPath.'"');
        }
        if (!isset($ctx->classes[VmPharData::CLASS_LC])) {
            PharDataBuiltin::register($ctx);
        }
        $out = new ObjectEntry($ctx->classes[VmPharData::CLASS_LC]);
        $out->constructed = true;
        VmPharData::bind($out, $outPath, $st['files'], false, $st['dirs']);

        return $out;
    }

    /**
     * php-src zim_Phar_convertToExecutable — already executable tar-backed Phar (#21328).
     */
    public static function convertToExecutable(ObjectEntry $object, Context $ctx, ?int $format = null, ?int $compression = null, ?string $extension = null): ObjectEntry
    {
        self::requireWritable('Phar::convertToExecutable');
        $st = self::requireState($object);
        self::ensureFlushed($object);
        if (null !== $format && VmPhar::FORMAT_TAR !== $format && VmPhar::FORMAT_PHAR !== $format && 0 !== $format) {
            throw new \BadMethodCallException('Only tar/phar format is supported for Phar::convertToExecutable() in this build');
        }
        if (null !== $compression && VmPhar::COMPRESSED_NONE !== $compression && VmPhar::COMPRESSED_GZ !== $compression && 0 !== $compression) {
            throw new \BadMethodCallException('Only NONE/GZ compression is supported for Phar::convertToExecutable() in this build');
        }
        $wantGz = null !== $compression && VmPhar::COMPRESSED_GZ === $compression;
        if ($wantGz) {
            return self::compress($object, $ctx, VmPhar::COMPRESSED_GZ, $extension);
        }
        $path = $st['path'];
        $outPath = $path;
        if (null !== $extension && '' !== $extension) {
            $base = \preg_replace('/(\.phar)?(\.(gz|bz2))?$/i', '', $path) ?? $path;
            $outPath = $base.'.'.\ltrim($extension, '.');
        }
        $binary = self::buildBinary($object);
        if ($outPath !== $path || VmPhar::COMPRESSED_NONE !== $st['wholeCompression']) {
            if (false === VmFs::filePutContents($outPath, $binary)) {
                throw new \UnexpectedValueException('phar error: unable to write phar "'.$outPath.'"');
            }
        }
        $out = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $out->constructed = true;
        self::bind(
            $out,
            $outPath,
            $st['files'],
            false,
            $st['dirs'],
            $st['stub'],
            $st['alias'],
            false,
            $st['hasMetadata'],
            $st['metadata'],
            VmPhar::COMPRESSED_NONE,
            0,
            '',
            null,
            $st['fileMetadata'] ?? [],
            $st['fileAttrs'] ?? []
        );

        return $out;
    }

    /**
     * php-src zim_Phar_isCompressed — whole-archive compression method or false (#21328).
     *
     * @return int|false
     */
    public static function isCompressed(ObjectEntry $object): int|false
    {
        $st = self::requireState($object);
        $c = $st['wholeCompression'];
        if (VmPhar::COMPRESSED_NONE === $c) {
            return false;
        }

        return $c;
    }

    /**
     * php-src zim_Phar_isFileFormat — this build is always tar-backed (#21328).
     */
    public static function isFileFormat(ObjectEntry $object, int $format): bool
    {
        self::requireState($object);

        return VmPhar::FORMAT_TAR === $format;
    }

    /** php-src zim_Phar_setDefaultStub — createDefaultStub + setStub (#21231). */
    public static function setDefaultStub(ObjectEntry $object, ?string $index = null, ?string $webIndex = null): bool
    {
        self::requireWritable('Phar::setDefaultStub');

        return self::setStub($object, self::createDefaultStub($index, $webIndex));
    }

    /**
     * php-src zim_Phar_copy — duplicate in-archive entry (#21231).
     */
    public static function copy(ObjectEntry $object, string $from, string $to): bool
    {
        self::requireWritable('Phar::copy');
        $st = self::requireState($object);
        $path = $st['path'];
        $from = self::normalizeEntryName($from);
        $to = self::normalizeEntryName($to);
        if ('' === $from || '' === $to) {
            throw new \UnexpectedValueException('Entry name cannot be empty');
        }
        $fromIsDir = isset($st['dirs'][$from]);
        $fromIsFile = isset($st['files'][$from]);
        if (!$fromIsDir && !$fromIsFile) {
            throw new \UnexpectedValueException(
                'file "'.$from.'" cannot be copied to file "'.$to.'", file does not exist in '.$path
            );
        }
        if (isset($st['files'][$to]) || isset($st['dirs'][$to])) {
            throw new \UnexpectedValueException(
                'file "'.$from.'" cannot be copied to file "'.$to.'", file must not already exist in phar '.$path
            );
        }
        if ($fromIsDir) {
            self::$state[$object->id]['dirs'][$to] = true;
        } else {
            self::$state[$object->id]['files'][$to] = $st['files'][$from];
        }
        self::markDirty($object);

        return true;
    }

    /**
     * php-src phar_load — validate archive and register alias (#21232).
     */
    public static function loadPhar(string $filename, string $alias = ''): bool
    {
        $filename = self::normalizeArchivePath($filename);
        self::assertReadablePharFile($filename);
        self::registerFilenameMap($filename);
        if ('' !== $alias) {
            self::registerAlias($alias, $filename);
        }

        return true;
    }

    public static function registerFilenameMap(string $path): void
    {
        self::$filenameMap[self::normalizeArchivePath($path)] = true;
    }

    public static function isRegisteredArchivePath(string $path): bool
    {
        return isset(self::$filenameMap[self::normalizeArchivePath($path)]);
    }

    public static function normalizeArchivePathPublic(string $path): string
    {
        return self::normalizeArchivePath($path);
    }

    /** php-src zim_Phar_setSignatureAlgorithm (#21329). */
    public static function setSignatureAlgorithm(ObjectEntry $object, int $algo, ?string $privateKey = null): void
    {
        if (!VmPhar::canWrite()) {
            throw new \UnexpectedValueException('Cannot set signature algorithm, phar is read-only');
        }
        VmPhar::assertSignatureAlgorithm($algo);
        self::requireState($object);
        self::$state[$object->id]['sigFlags'] = $algo;
        self::$state[$object->id]['sigPrivateKey'] = $privateKey;
        self::$state[$object->id]['dirty'] = true;
        self::flush($object);
    }

    /**
     * @return array{hash: string, hash_type: string}|false
     */
    public static function getSignature(ObjectEntry $object): array|false
    {
        $st = self::requireState($object);
        if (0 === $st['sigFlags'] || '' === $st['signature']) {
            return false;
        }

        return [
            'hash' => $st['signature'],
            'hash_type' => VmPhar::signatureHashTypeName($st['sigFlags']),
        ];
    }

    public static function registerAlias(string $alias, string $filename): void
    {
        self::$aliases[$alias] = self::normalizeArchivePath($filename);
    }

    public static function resolveAliasPath(string $alias): ?string
    {
        return self::$aliases[$alias] ?? null;
    }

    /**
     * @return array{files: array<string, string>, dirs: array<string, true>}|null
     */
    public static function liveEntriesForPath(string $path): ?array
    {
        $path = self::normalizeArchivePath($path);
        foreach (self::$state as $st) {
            if ($st['path'] !== $path) {
                continue;
            }

            return ['files' => $st['files'], 'dirs' => $st['dirs']];
        }

        return null;
    }

    public static function assertReadablePharFilePublic(string $path): void
    {
        self::assertReadablePharFile($path);
    }

    public static function normalizeEntryNamePublic(string $localname): string
    {
        return self::normalizeEntryName($localname);
    }

    /** @return array{0: string, 1: string} */
    public static function splitStubPublic(string $binary): array
    {
        return self::splitStub($binary);
    }

    /**
     * php-src phar_unlink_archive — drop in-memory state and unlink on disk (#21232).
     */
    public static function unlinkArchive(string $archive): bool
    {
        $archive = self::normalizeArchivePath($archive);
        foreach (self::$state as $oid => $st) {
            if ($st['path'] !== $archive) {
                continue;
            }
            $live = ObjectRegistry::find($oid);
            if (null !== $live && $live->refCount > 0) {
                throw new \PharException(
                    'phar archive "'.$archive.'" has open file handles or objects.  fclose() all file handles, and unset() all objects prior to calling unlinkArchive()'
                );
            }
            unset(self::$state[$oid]);
        }
        foreach (self::$aliases as $alias => $path) {
            if ($path === $archive) {
                unset(self::$aliases[$alias]);
            }
        }
        if (!VmStatPath::isFile($archive)) {
            throw new \PharException(
                'Unknown phar archive "'.$archive.'": unable to open phar for reading "'.$archive.'"'
            );
        }
        if (!VmFs::unlink($archive)) {
            throw new \UnexpectedValueException('phar error: unable to delete phar "'.$archive.'"');
        }

        return true;
    }

    public static function offsetSet(ObjectEntry $object, string $localname, string $contents): void
    {
        self::addFromString($object, $localname, $contents);
    }

    public static function offsetExists(ObjectEntry $object, string $localname): bool
    {
        $st = self::requireState($object);
        $localname = \rtrim(\ltrim(\str_replace('\\', '/', $localname), '/'), '/');

        return isset($st['files'][$localname]) || isset($st['dirs'][$localname]);
    }

    public static function offsetGet(ObjectEntry $object, string $localname, Context $ctx): Variable
    {
        $st = self::requireState($object);
        $localname = \rtrim(\ltrim(\str_replace('\\', '/', $localname), '/'), '/');
        if (isset($st['dirs'][$localname])) {
            throw new \BadMethodCallException('Entry '.$localname.' is a directory');
        }
        if (!isset($st['files'][$localname])) {
            throw new \BadMethodCallException('Entry '.$localname.' does not exist');
        }
        $var = new Variable(Variable::TYPE_OBJECT);
        $info = VmPharFileInfo::createFromEntry($ctx, $st['path'], $localname, $st['files'][$localname]);
        $entryMeta = self::getEntryMetadata($st['path'], $localname);
        if ($entryMeta['has']) {
            VmPharFileInfo::hydrateMetadata($info, $entryMeta['value']);
        }
        $attrs = self::getEntryAttrs($st['path'], $localname);
        VmPharFileInfo::hydrateAttrs($info, $attrs['perms'], $attrs['flags']);
        $var->object($info);

        return $var;
    }

    public static function offsetUnset(ObjectEntry $object, string $localname): void
    {
        self::requireWritable('Phar::offsetUnset');
        self::requireState($object);
        $localname = \rtrim(\ltrim(\str_replace('\\', '/', $localname), '/'), '/');
        unset(self::$state[$object->id]['files'][$localname], self::$state[$object->id]['dirs'][$localname]);
        unset(self::$state[$object->id]['fileMetadata'][$localname]);
        unset(self::$state[$object->id]['fileAttrs'][$localname]);
        self::markDirty($object);
    }

    public static function path(ObjectEntry $object): string
    {
        return self::requireState($object)['path'];
    }

    public static function createDefaultStub(?string $index = null, ?string $webIndex = null): string
    {
        $index = null === $index || '' === $index ? 'index.php' : $index;
        $webIndex = null === $webIndex || '' === $webIndex ? $index : $webIndex;

        return "#!/usr/bin/env php\n<?php\n"
            ."Phar::mapPhar();\n"
            ."\$web = '".$webIndex."';\n"
            ."\$cli = '".$index."';\n"
            ."if (PHP_SAPI === 'cli') {\n"
            ."    include 'phar://'.__FILE__.'/'.\$cli;\n"
            ."} else {\n"
            ."    include 'phar://'.__FILE__.'/'.\$web;\n"
            ."}\n"
            ."__HALT_COMPILER(); ?>";
    }

    public static function mapToHashTable(array $map): HashTable
    {
        return VmPharData::mapToHashTable($map);
    }

    public static function requireReceiver(Frame $frame, string $method): ObjectEntry
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \Error($method.'(): must be called on Phar object');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError($method.'(): must be called on Phar object');
        }
        $object = $receiver->toObject();
        if (self::CLASS_LC !== \strtolower($object->class->name)) {
            throw new \TypeError($method.'(): must be called on Phar object');
        }

        return $object;
    }

    public static function coercePathArg(Variable $operand, string $function, int $argIndex, string $param): string
    {
        return VmString::coerceStringBuiltinArg($operand, $function, $argIndex, $param);
    }

    private static function markDirty(ObjectEntry $object): void
    {
        self::$state[$object->id]['dirty'] = true;
        if (!self::$state[$object->id]['buffering']) {
            self::flush($object);
        }
    }

    private static function ensureFlushed(ObjectEntry $object): void
    {
        $st = self::requireState($object);
        if ($st['dirty']) {
            self::flush($object);
        }
    }

    /** Build on-disk stub+tar bytes (uncompressed). */
    private static function buildBinary(ObjectEntry $object): string
    {
        $st = self::requireState($object);
        $stub = $st['stub'];
        if (!\str_contains($stub, self::HALT)) {
            if (!\str_ends_with(\rtrim($stub), '?>')) {
                $stub .= "\n".self::HALT;
            } else {
                $stub .= self::HALT;
            }
        }
        $files = $st['files'];
        if ($st['hasMetadata']) {
            $files[self::META_ENTRY] = \serialize($st['metadata']);
        }
        if ([] !== ($st['fileMetadata'] ?? [])) {
            $files[self::ENTRY_META] = \serialize($st['fileMetadata']);
        }
        if ([] !== ($st['fileAttrs'] ?? [])) {
            $files[self::ENTRY_ATTRS] = \serialize($st['fileAttrs']);
        }

        return $stub.VmPharTar::writeArchive($files, $st['dirs']);
    }

    private static function flush(ObjectEntry $object): void
    {
        $st = self::requireState($object);
        if (!$st['dirty']) {
            return;
        }
        if (!VmPhar::canWrite()) {
            throw new \UnexpectedValueException(
                'Cannot write to archive - write operations disabled by the php.ini setting phar.readonly'
            );
        }
        $path = $st['path'];
        $binary = self::buildBinary($object);
        if (VmPhar::COMPRESSED_GZ === $st['wholeCompression']) {
            $encoded = VmZlib::gzencode($binary);
            if (false === $encoded) {
                throw new \UnexpectedValueException('phar error: unable to write phar "'.$path.'"');
            }
            $binary = $encoded;
        }
        if (false === VmFs::filePutContents($path, $binary)) {
            throw new \UnexpectedValueException('phar error: unable to write phar "'.$path.'"');
        }
        self::refreshSignature($object, $binary);
        self::$state[$object->id]['dirty'] = false;
    }

    private static function refreshSignature(ObjectEntry $object, string $binary): void
    {
        $sigFlags = self::$state[$object->id]['sigFlags'];
        if (0 === $sigFlags) {
            self::$state[$object->id]['signature'] = '';

            return;
        }
        if (\in_array($sigFlags, [VmPhar::SIG_OPENSSL, VmPhar::SIG_OPENSSL_SHA256, VmPhar::SIG_OPENSSL_SHA512], true)) {
            $key = self::$state[$object->id]['sigPrivateKey'];
            if (null === $key || '' === $key) {
                throw new \PharException('no private key specified');
            }
            $digestAlgo = match ($sigFlags) {
                VmPhar::SIG_OPENSSL_SHA256 => OPENSSL_ALGO_SHA256,
                VmPhar::SIG_OPENSSL_SHA512 => OPENSSL_ALGO_SHA512,
                default => OPENSSL_ALGO_SHA1,
            };
            $signature = '';
            $signed = \openssl_sign($binary, $signature, $key, $digestAlgo);
            if (!$signed) {
                throw new \PharException('openssl signing failed');
            }
            self::$state[$object->id]['signature'] = $signature;

            return;
        }
        self::$state[$object->id]['signature'] = VmPhar::computeHashSignature($binary, $sigFlags);
    }

    /** @return array{0: string, 1: string} stub + payload */
    private static function splitStub(string $binary): array
    {
        $pos = \strpos($binary, self::HALT);
        if (false === $pos) {
            $pos = \strpos($binary, '__HALT_COMPILER();');
            if (false === $pos) {
                return [self::createDefaultStub(), $binary];
            }
            $end = \strpos($binary, '?>', $pos);
            if (false === $end) {
                return [self::createDefaultStub(), $binary];
            }
            $stubEnd = $end + 2;

            return [\substr($binary, 0, $stubEnd), \substr($binary, $stubEnd)];
        }
        $stubEnd = $pos + \strlen(self::HALT);

        return [\substr($binary, 0, $stubEnd), \substr($binary, $stubEnd)];
    }

    /** @return array{path: string, files: array<string, string>, dirs: array<string, true>, dirty: bool, buffering: bool, stub: string, alias: string, hasMetadata: bool, metadata: mixed, wholeCompression: int, sigFlags: int, signature: string, sigPrivateKey: ?string} */
    private static function requireState(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id])) {
            throw new \Error('Phar is uninitialized');
        }

        return self::$state[$object->id];
    }

    private static function normalizeEntryName(string $localname): string
    {
        return \rtrim(\ltrim(\str_replace('\\', '/', $localname), '/'), '/');
    }

    private static function normalizeArchivePath(string $path): string
    {
        return \str_replace('\\', '/', $path);
    }

    private static function assertReadablePharFile(string $path): void
    {
        if (!VmStatPath::isFile($path)) {
            throw new \PharException(
                'Unknown phar archive "'.$path.'": unable to open phar for reading "'.$path.'"'
            );
        }
        $binary = VmFs::fileGetContents($path);
        if (false === $binary) {
            throw new \PharException(
                'Unknown phar archive "'.$path.'": unable to open phar for reading "'.$path.'"'
            );
        }
        if (\strlen($binary) >= 2 && "\x1f" === $binary[0] && "\x8b" === $binary[1]) {
            $decoded = VmZlib::gzdecode($binary);
            if (false === $decoded) {
                throw new \PharException(
                    'Unknown phar archive "'.$path.'": unable to open phar for reading "'.$path.'"'
                );
            }
            $binary = $decoded;
        }
        if (!\str_contains($binary, '__HALT_COMPILER()')) {
            throw new \PharException(
                'internal corruption of phar "'.$path.'" (__HALT_COMPILER(); not found)'
            );
        }
        [, $payload] = self::splitStub($binary);
        VmPharTar::readArchiveEntries($payload);
    }
}
