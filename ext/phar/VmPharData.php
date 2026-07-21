<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStatPath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\standard\VmZlib;
use PHPCompiler\ext\zip\ZipEngine;

/** PharData archive state — php-src ext/phar/phar_object.c (#6490, #19893, #21676). */
final class VmPharData
{
    public const CLASS_LC = 'phardata';

    /** @var array<int, array{path: string, files: array<string, string>, dirs: array<string, true>, dirty: bool, sigFlags: int, signature: string, sigPrivateKey: ?string, format: int}> */
    private static array $state = [];

    public static function bind(
        ObjectEntry $object,
        string $path,
        array $files,
        bool $dirty,
        array $dirs = [],
        int $sigFlags = 0,
        string $signature = '',
        ?string $sigPrivateKey = null,
        int $format = VmPhar::FORMAT_TAR
    ): void {
        self::$state[$object->id] = [
            'path' => $path,
            'files' => $files,
            'dirs' => $dirs,
            'dirty' => $dirty,
            'sigFlags' => $sigFlags,
            'signature' => $signature,
            'sigPrivateKey' => $sigPrivateKey,
            'format' => $format,
        ];
    }

    public static function open(ObjectEntry $object, string $path): void
    {
        $path = \str_replace('\\', '/', $path);
        // Prefer content probe over isFile — ZipEngine/VmFsWriteNative paths can be
        // readable via fileGetContents while VmStatPath::isFile is false (#21676).
        $binary = VmFs::fileGetContents($path);
        if (false !== $binary) {
            if (self::looksLikeZip($binary)) {
                self::openZipBytes($object, $path, $binary);

                return;
            }
            if (\strlen($binary) >= 2 && "\x1f" === $binary[0] && "\x8b" === $binary[1]) {
                $decoded = VmZlib::gzdecode($binary);
                if (false === $decoded) {
                    throw new \UnexpectedValueException('phar error: unable to open phar "'.$path.'"');
                }
                $binary = $decoded;
            }
            $entries = VmPharTar::readArchiveEntries($binary);
            self::bind($object, $path, $entries['files'], false, $entries['dirs'], 0, '', null, VmPhar::FORMAT_TAR);

            return;
        }
        $dir = \dirname($path);
        if ('.' !== $dir && !VmStatPath::isDir($dir) && !VmStatPath::isFile($dir)) {
            throw new \UnexpectedValueException('phar error: unable to create phar "'.$path.'"');
        }
        $format = self::formatFromPath($path);
        self::bind($object, $path, [], true, [], 0, '', null, $format);
    }

    /** php-src zim_Phar_isFileFormat for PharData (#21676). */
    public static function isFileFormat(ObjectEntry $object, int $format): bool
    {
        $st = self::requireState($object);

        return ($st['format'] ?? VmPhar::FORMAT_TAR) === $format;
    }

    public static function addFromString(ObjectEntry $object, string $localname, string $contents): void
    {
        self::requireState($object);
        $localname = \ltrim(\str_replace('\\', '/', $localname), '/');
        if ('' === $localname) {
            throw new \UnexpectedValueException('Entry name cannot be empty');
        }
        self::$state[$object->id]['files'][$localname] = $contents;
        unset(self::$state[$object->id]['dirs'][$localname]);
        self::$state[$object->id]['dirty'] = true;
        self::flush($object);
    }

    public static function addEmptyDir(ObjectEntry $object, string $dirname): void
    {
        $dirname = \rtrim(\ltrim(\str_replace('\\', '/', $dirname), '/'), '/');
        if ('' === $dirname) {
            throw new \UnexpectedValueException('Directory name cannot be empty');
        }
        self::requireState($object);
        self::$state[$object->id]['dirs'][$dirname] = true;
        unset(self::$state[$object->id]['files'][$dirname]);
        self::$state[$object->id]['dirty'] = true;
        self::flush($object);
    }

    public static function addFile(ObjectEntry $object, string $filename, ?string $localname = null): void
    {
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

    public static function compress(ObjectEntry $object, Context $ctx, int $compression, ?string $extension = null): ObjectEntry
    {
        $st = self::requireState($object);
        self::flush($object);
        if (VmPhar::COMPRESSED_GZ !== $compression) {
            throw new \BadMethodCallException('Only gzip compression is supported for PharData in this build');
        }
        if (!VmPhar::canCompress(VmPhar::COMPRESSED_GZ)) {
            throw new \BadMethodCallException('zlib extension is required for gzip compression');
        }
        $ext = null !== $extension && '' !== $extension ? \ltrim($extension, '.') : 'gz';
        $path = $st['path'];
        $outPath = \str_ends_with(\strtolower($path), '.'.$ext) ? $path : $path.'.'.$ext;
        $binary = VmPharTar::writeArchive($st['files'], $st['dirs']);
        $encoded = VmZlib::gzencode($binary);
        if (false === $encoded || false === VmFs::filePutContents($outPath, $encoded)) {
            throw new \UnexpectedValueException('phar error: unable to write phar "'.$outPath.'"');
        }
        $out = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $out->constructed = true;
        self::bind($out, $outPath, $st['files'], false, $st['dirs'], 0, '', null, $st['format'] ?? VmPhar::FORMAT_TAR);

        return $out;
    }

    public static function decompress(ObjectEntry $object, Context $ctx, ?string $extension = null): ObjectEntry
    {
        $st = self::requireState($object);
        self::flush($object);
        $path = $st['path'];
        $outPath = $path;
        foreach (['.tar.gz', '.tar.bz2', '.gz', '.bz2'] as $suffix) {
            if (\str_ends_with(\strtolower($path), $suffix)) {
                $outPath = \substr($path, 0, -\strlen($suffix));
                if ('.gz' === $suffix || '.bz2' === $suffix) {
                    // keep
                }
                break;
            }
        }
        if (null !== $extension && '' !== $extension) {
            $outPath = \preg_replace('/\.[^.]+$/', '', $outPath).'.'.\ltrim($extension, '.');
        }
        $binary = VmPharTar::writeArchive($st['files'], $st['dirs']);
        if (false === VmFs::filePutContents($outPath, $binary)) {
            throw new \UnexpectedValueException('phar error: unable to write phar "'.$outPath.'"');
        }
        $out = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $out->constructed = true;
        self::bind($out, $outPath, $st['files'], false, $st['dirs'], 0, '', null, $st['format'] ?? VmPhar::FORMAT_TAR);

        return $out;
    }

    public static function convertToData(ObjectEntry $object, Context $ctx): ObjectEntry
    {
        $st = self::requireState($object);
        self::flush($object);
        $out = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $out->constructed = true;
        self::bind($out, $st['path'], $st['files'], false, $st['dirs'], 0, '', null, $st['format'] ?? VmPhar::FORMAT_TAR);

        return $out;
    }

    public static function convertToExecutable(ObjectEntry $object): void
    {
        if (!VmPhar::canWrite()) {
            throw new \BadMethodCallException('Cannot write out executable phar archive, phar is read-only');
        }
        throw new \BadMethodCallException('PharData::convertToExecutable() is not supported for tar PharData in this build');
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
        $var->object($info);

        return $var;
    }

    public static function path(ObjectEntry $object): string
    {
        return self::requireState($object)['path'];
    }

    /** php-src zim_Phar_setSignatureAlgorithm on PharData (#21329). */
    public static function setSignatureAlgorithm(ObjectEntry $object, int $algo, ?string $privateKey = null): void
    {
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

    public static function mapToHashTable(array $map): HashTable
    {
        $ht = new HashTable();
        foreach ($map as $k => $v) {
            $val = new Variable(Variable::TYPE_STRING);
            $val->string((string) $v);
            $ht->add((string) $k, $val);
        }

        return $ht;
    }

    public static function requireReceiver(Frame $frame, string $method): ObjectEntry
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \Error($method.'(): must be called on PharData object');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError($method.'(): must be called on PharData object');
        }
        $object = $receiver->toObject();
        if (self::CLASS_LC !== \strtolower($object->class->name)) {
            throw new \TypeError($method.'(): must be called on PharData object');
        }

        return $object;
    }

    public static function coercePathArg(Variable $operand, string $function, int $argIndex, string $param): string
    {
        return VmString::coerceStringBuiltinArg($operand, $function, $argIndex, $param);
    }

    private static function flush(ObjectEntry $object): void
    {
        $st = self::requireState($object);
        if (!$st['dirty']) {
            return;
        }
        $path = $st['path'];
        $format = $st['format'] ?? VmPhar::FORMAT_TAR;
        if (VmPhar::FORMAT_ZIP === $format) {
            self::flushZip($object, $path, $st['files'], $st['dirs']);
            self::$state[$object->id]['dirty'] = false;

            return;
        }
        $binary = VmPharTar::writeArchive($st['files'], $st['dirs']);
        $lower = \strtolower($path);
        if (\str_ends_with($lower, '.gz') || \str_ends_with($lower, '.tgz')) {
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

    private static function looksLikeZip(string $binary): bool
    {
        if (\strlen($binary) < 4) {
            return false;
        }

        return 'PK' === \substr($binary, 0, 2);
    }

    private static function formatFromPath(string $path): int
    {
        $lower = \strtolower($path);
        if (\str_ends_with($lower, '.zip')) {
            return VmPhar::FORMAT_ZIP;
        }

        return VmPhar::FORMAT_TAR;
    }

    private static function openZipBytes(ObjectEntry $object, string $path, string $binary): void
    {
        if (!\class_exists(ZipEngine::class, false)) {
            require_once __DIR__.'/../zip/ZipEngine.php';
        }
        $parsed = ZipEngine::readArchiveBytes($binary);
        if (!($parsed['ok'] ?? false)) {
            throw new \UnexpectedValueException('phar error: unable to open phar "'.$path.'"');
        }
        $files = [];
        $dirs = [];
        foreach ($parsed['entries'] as $entry) {
            $name = \str_replace('\\', '/', (string) ($entry['name'] ?? ''));
            if ('' === $name) {
                continue;
            }
            if (\str_ends_with($name, '/')) {
                $dirs[\rtrim($name, '/')] = true;
                continue;
            }
            $files[$name] = (string) ($entry['data'] ?? '');
        }
        self::bind($object, $path, $files, false, $dirs, 0, '', null, VmPhar::FORMAT_ZIP);
    }

    /**
     * @param array<string, string> $files
     * @param array<string, true>   $dirs
     */
    private static function flushZip(ObjectEntry $object, string $path, array $files, array $dirs): void
    {
        if (!\class_exists(ZipEngine::class, false)) {
            require_once __DIR__.'/../zip/ZipEngine.php';
        }
        $entries = [];
        foreach ($dirs as $name => $_) {
            $name = \rtrim(\str_replace('\\', '/', (string) $name), '/');
            if ('' === $name) {
                continue;
            }
            $entries[] = [
                'name' => $name.'/',
                'data' => '',
                'crc' => 0,
                'size' => 0,
            ];
        }
        foreach ($files as $name => $contents) {
            $name = \ltrim(\str_replace('\\', '/', (string) $name), '/');
            if ('' === $name) {
                continue;
            }
            $entries[] = [
                'name' => $name,
                'data' => $contents,
                'crc' => 0,
                'size' => \strlen($contents),
            ];
        }
        if (!ZipEngine::writeArchive($path, $entries)) {
            throw new \UnexpectedValueException('phar error: unable to write phar "'.$path.'"');
        }
        self::refreshSignature($object, (string) VmFs::fileGetContents($path));
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

    /** @return array{path: string, files: array<string, string>, dirs: array<string, true>, dirty: bool, sigFlags: int, signature: string, sigPrivateKey: ?string} */
    private static function requireState(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id])) {
            throw new \Error('PharData is uninitialized');
        }
        if (!isset(self::$state[$object->id]['dirs'])) {
            self::$state[$object->id]['dirs'] = [];
        }

        return self::$state[$object->id];
    }
}
