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

    /** @var array<int, array{path: string, files: array<string, string>, dirs: array<string, true>, dirty: bool, buffering: bool, stub: string, alias: string}> */
    private static array $state = [];

    public static function bind(
        ObjectEntry $object,
        string $path,
        array $files,
        bool $dirty,
        array $dirs = [],
        string $stub = '',
        string $alias = '',
        bool $buffering = false
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
        ];
    }

    public static function open(ObjectEntry $object, string $path): void
    {
        $path = \str_replace('\\', '/', $path);
        if (VmStatPath::isFile($path)) {
            $binary = VmFs::fileGetContents($path);
            if (false === $binary) {
                throw new \UnexpectedValueException('phar error: unable to open phar "'.$path.'"');
            }
            [$stub, $payload] = self::splitStub($binary);
            $entries = VmPharTar::readArchiveEntries($payload);
            self::bind($object, $path, $entries['files'], false, $entries['dirs'], $stub);

            return;
        }
        $dir = \dirname($path);
        if ('.' !== $dir && !VmStatPath::isDir($dir) && !VmStatPath::isFile($dir)) {
            throw new \UnexpectedValueException('phar error: unable to create phar "'.$path.'"');
        }
        self::bind($object, $path, [], true, []);
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

    public static function offsetUnset(ObjectEntry $object, string $localname): void
    {
        self::requireWritable('Phar::offsetUnset');
        self::requireState($object);
        $localname = \rtrim(\ltrim(\str_replace('\\', '/', $localname), '/'), '/');
        unset(self::$state[$object->id]['files'][$localname], self::$state[$object->id]['dirs'][$localname]);
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
        $stub = $st['stub'];
        if (!\str_contains($stub, self::HALT)) {
            if (!\str_ends_with(\rtrim($stub), '?>')) {
                $stub .= "\n".self::HALT;
            } else {
                $stub .= self::HALT;
            }
        }
        $binary = $stub.VmPharTar::writeArchive($st['files'], $st['dirs']);
        if (false === VmFs::filePutContents($path, $binary)) {
            throw new \UnexpectedValueException('phar error: unable to write phar "'.$path.'"');
        }
        self::$state[$object->id]['dirty'] = false;
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

    /** @return array{path: string, files: array<string, string>, dirs: array<string, true>, dirty: bool, buffering: bool, stub: string, alias: string} */
    private static function requireState(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id])) {
            throw new \Error('Phar is uninitialized');
        }

        return self::$state[$object->id];
    }
}
