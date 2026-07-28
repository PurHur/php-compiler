<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ObjectEntry;

/**
 * Backing pathname + info/file class factories for SplFileInfo / SplFileObject
 * (php-src ext/spl/spl_directory.c — spl_filesystem_info_set_filename).
 */
final class SplFileInfoStorage
{
    /**
     * @var array<int, array{pathname: string, path: string, infoClassLc: string, fileClassLc: string}>
     */
    private static array $store = [];

    public static function init(ObjectEntry $object, string $pathname): void
    {
        $prev = self::$store[$object->id] ?? null;
        [$fileName, $path] = self::splitPathComponents($pathname);
        self::$store[$object->id] = [
            'pathname' => $fileName,
            'path' => $path,
            // Preserve setInfoClass/setFileClass across DirectoryIterator/GlobIterator path sync.
            'infoClassLc' => $prev['infoClassLc'] ?? SplFileInfoBuiltin::CLASS_LC,
            'fileClassLc' => $prev['fileClassLc'] ?? SplFileObjectBuiltin::CLASS_LC,
        ];
    }

    public static function pathname(ObjectEntry $object): string
    {
        return self::state($object)['pathname'];
    }

    /**
     * Directory component stored by php-src (may be "" when the only slash is leading).
     * Distinct from VmString::dirname() which returns "/" / "." for those cases.
     */
    public static function path(ObjectEntry $object): string
    {
        return self::state($object)['path'];
    }

    /**
     * SplFileInfo::getFilename() / private fileName debug bag — not php basename().
     * php-src: when path is non-empty and shorter than file_name, skip path + '/'.
     */
    public static function filename(ObjectEntry $object): string
    {
        $state = self::state($object);
        $fileName = $state['pathname'];
        $path = $state['path'];
        $pathLen = \strlen($path);
        if (0 !== $pathLen && $pathLen < \strlen($fileName)) {
            return \substr($fileName, $pathLen + 1);
        }

        return $fileName;
    }

    public static function infoClassLc(ObjectEntry $object): string
    {
        return self::state($object)['infoClassLc'];
    }

    public static function fileClassLc(ObjectEntry $object): string
    {
        return self::state($object)['fileClassLc'];
    }

    public static function setInfoClassLc(ObjectEntry $object, string $classLc): void
    {
        self::state($object);
        self::$store[$object->id]['infoClassLc'] = $classLc;
    }

    public static function setFileClassLc(ObjectEntry $object, string $classLc): void
    {
        self::state($object);
        self::$store[$object->id]['fileClassLc'] = $classLc;
    }

    public static function hasState(ObjectEntry $object): bool
    {
        return isset(self::$store[$object->id]);
    }

    /**
     * php-src spl_filesystem_info_set_filename: strip trailing slashes (keep "/"),
     * then split path so a leading-only slash leaves path="" and file_name with the slash.
     *
     * @return array{0: string, 1: string} [file_name, path]
     */
    public static function splitPathComponents(string $path): array
    {
        $pathLen = \strlen($path);
        if ($pathLen > 1 && self::isSlashAt($path, $pathLen - 1)) {
            do {
                --$pathLen;
            } while ($pathLen > 1 && self::isSlashAt($path, $pathLen - 1));
            $fileName = \substr($path, 0, $pathLen);
        } else {
            $fileName = $path;
        }
        while ($pathLen > 1 && !self::isSlashAt($path, $pathLen - 1)) {
            --$pathLen;
        }
        if ($pathLen > 0) {
            --$pathLen;
        }
        $dir = 0 === $pathLen ? '' : \substr($path, 0, $pathLen);

        return [$fileName, $dir];
    }

    private static function isSlashAt(string $path, int $index): bool
    {
        $byte = $path[$index];
        if ('/' === $byte) {
            return true;
        }

        return '\\' === $byte && 'Windows' === \PHP_OS_FAMILY;
    }

    /** @return array{pathname: string, path: string, infoClassLc: string, fileClassLc: string} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('SplFileInfo object state missing');
        }

        return self::$store[$object->id];
    }
}
