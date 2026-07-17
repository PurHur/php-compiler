<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ObjectEntry;

/**
 * Backing pathname + info/file class factories for SplFileInfo / SplFileObject
 * (php-src ext/spl/spl_directory.c).
 */
final class SplFileInfoStorage
{
    /**
     * @var array<int, array{pathname: string, infoClassLc: string, fileClassLc: string}>
     */
    private static array $store = [];

    public static function init(ObjectEntry $object, string $pathname): void
    {
        $prev = self::$store[$object->id] ?? null;
        self::$store[$object->id] = [
            'pathname' => $pathname,
            // Preserve setInfoClass/setFileClass across DirectoryIterator/GlobIterator path sync.
            'infoClassLc' => $prev['infoClassLc'] ?? SplFileInfoBuiltin::CLASS_LC,
            'fileClassLc' => $prev['fileClassLc'] ?? SplFileObjectBuiltin::CLASS_LC,
        ];
    }

    public static function pathname(ObjectEntry $object): string
    {
        return self::state($object)['pathname'];
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

    /** @return array{pathname: string, infoClassLc: string, fileClassLc: string} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('SplFileInfo object state missing');
        }

        return self::$store[$object->id];
    }
}
