<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\ext\standard\VmFsDirNative;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmFsWriteNative;
use PHPCompiler\ext\standard\VmStatPath;
use PHPCompiler\VM\Variable;

/**
 * ZipArchive VM implementation in PHP (php-src ext/zip/php_zip.c; issues #6413, #6414).
 */
final class VmZipArchive
{
    public const CLASS_LC = 'ziparchive';

    public const PROP_STATUS = 'status';

    public const PROP_FILENAME = 'filename';

    public const PROP_NUM_FILES = 'numFiles';

    /** @var array<int, ZipArchiveState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $intProto = new Variable(Variable::TYPE_INTEGER);
        $strProto = new Variable(Variable::TYPE_STRING);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry = new ClassEntry('ZipArchive');
        $entry->isInternal = true;
        $entry->properties[] = new ClassProperty(self::PROP_STATUS, null, $intProto);
        $entry->properties[] = new ClassProperty(self::PROP_FILENAME, null, $strProto);
        $entry->properties[] = new ClassProperty(self::PROP_NUM_FILES, null, $intProto);

        foreach (ZipArchiveConstants::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = ZipArchiveConstants::CLASS_CONSTANT_NAMES[$name];
        }

        $entry->constructor = new ZipArchiveConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'open' => new ZipArchiveOpen(),
            'close' => new ZipArchiveClose(),
            'addfile' => new ZipArchiveAddFile(),
            'addfromstring' => new ZipArchiveAddFromString(),
            'getfromname' => new ZipArchiveGetFromName(),
            'extractto' => new ZipArchiveExtractTo(),
            'getstatusstring' => new ZipArchiveGetStatusString(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
            $entry->methodNames[$name] = self::methodDisplayName($name);
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $entry): void
    {
        $state = new ZipArchiveState();
        self::$store[$entry->id] = $state;
        self::syncProperties($entry, $state);
        $entry->constructed = true;
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on ZipArchive, %s given', $label, self::typeLabel($var)));
        }
        $object = $var->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError(\sprintf('%s must be called on ZipArchive, %s given', $label, $object->class->name));
        }
        if (!$object->constructed) {
            throw new \TypeError(\sprintf('%s must be called on ZipArchive, uninitialized %s given', $label, $object->class->name));
        }

        return $object;
    }

    public static function state(ObjectEntry $entry): ZipArchiveState
    {
        $state = self::$store[$entry->id] ?? null;
        if (null === $state) {
            self::initObject($entry);
            $state = self::$store[$entry->id] ?? null;
        }
        if (null === $state) {
            throw new \LogicException('ZipArchive internal state missing in this compiler build');
        }

        return $state;
    }

    public static function coerceStringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type string, %s given',
                $label,
                $index,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type string, %s given',
                $label,
                $index,
                $paramName,
                self::typeLabel($var)
            ));
        }

        return $var->toString();
    }

    public static function coerceIntArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        if (\func_num_args() >= 5 && Variable::TYPE_NULL === $var->resolveIndirect()->type) {
            return $default;
        }
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $label,
                $index,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $label,
                $index,
                $paramName,
                self::typeLabel($var)
            ));
        }

        return $var->toInt();
    }

    /**
     * @return true|int TRUE on success or ZipArchive error code on failure (php-src parity).
     */
    public static function open(ObjectEntry $entry, string $filename, int $flags = 0): bool|int
    {
        $state = self::state($entry);
        if ($state->open) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return ZipArchiveConstants::ER_INVAL;
        }

        $exists = file_exists($filename);
        if ($exists && ($flags & ZipArchiveConstants::EXCL)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_EXISTS);

            return ZipArchiveConstants::ER_EXISTS;
        }

        if (!$exists) {
            if (0 === ($flags & ZipArchiveConstants::CREATE)) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

                return ZipArchiveConstants::ER_NOENT;
            }
            $state->entries = [];
        } elseif ($flags & ZipArchiveConstants::OVERWRITE) {
            $state->entries = [];
        } else {
            $read = ZipEngine::readArchive($filename);
            if (!$read['ok']) {
                self::setStatus($entry, $state, $read['code']);

                return $read['code'];
            }
            $state->entries = $read['entries'];
        }

        $state->filename = $filename;
        $state->open = true;
        $state->dirty = false;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    public static function close(ObjectEntry $entry): bool
    {
        $state = self::state($entry);
        if (!$state->open) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        if ($state->dirty || $state->open) {
            if (!ZipEngine::writeArchive($state->filename, $state->entries)) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_WRITE);

                return false;
            }
        }
        $state->open = false;
        $state->dirty = false;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    public static function addFile(ObjectEntry $entry, string $filepath, string $entryname = ''): bool
    {
        $state = self::state($entry);
        if (!$state->open) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_ZIPCLOSED);

            return false;
        }
        if ('' === $entryname) {
            $entryname = basename($filepath);
        }
        if (!is_file($filepath) || !is_readable($filepath)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

            return false;
        }
        $data = VmFsReadNative::read($filepath);
        if (false === $data) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_READ);

            return false;
        }
        $size = strlen($data);
        $crc = self::crc32Unsigned($data);
        foreach ($state->entries as $idx => $existing) {
            if ($existing['name'] === $entryname) {
                $state->entries[$idx] = [
                    'name' => $entryname,
                    'data' => $data,
                    'crc' => $crc,
                    'size' => $size,
                ];
                $state->dirty = true;
                self::syncProperties($entry, $state);
                self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

                return true;
            }
        }
        $state->entries[] = [
            'name' => $entryname,
            'data' => $data,
            'crc' => $crc,
            'size' => $size,
        ];
        $state->dirty = true;
        self::syncProperties($entry, $state);
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    public static function addFromString(ObjectEntry $entry, string $name, string $content): bool
    {
        $state = self::state($entry);
        if (!$state->open) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_ZIPCLOSED);

            return false;
        }
        $size = strlen($content);
        $crc = self::crc32Unsigned($content);
        foreach ($state->entries as $idx => $existing) {
            if ($existing['name'] === $name) {
                $state->entries[$idx] = [
                    'name' => $name,
                    'data' => $content,
                    'crc' => $crc,
                    'size' => $size,
                ];
                $state->dirty = true;
                self::syncProperties($entry, $state);
                self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

                return true;
            }
        }
        $state->entries[] = [
            'name' => $name,
            'data' => $content,
            'crc' => $crc,
            'size' => $size,
        ];
        $state->dirty = true;
        self::syncProperties($entry, $state);
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    public static function getFromName(ObjectEntry $entry, string $name): string|false
    {
        $state = self::state($entry);
        if (!$state->open) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_ZIPCLOSED);

            return false;
        }
        foreach ($state->entries as $zipEntry) {
            if ($zipEntry['name'] === $name) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

                return $zipEntry['data'];
            }
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

        return false;
    }

    public static function extractTo(ObjectEntry $entry, string $pathto, ?Variable $files = null): bool
    {
        $state = self::state($entry);
        if (!$state->open) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_ZIPCLOSED);

            return false;
        }
        if (!is_dir($pathto) || !is_writable($pathto)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_OPEN);

            return false;
        }

        $selected = null;
        if (null !== $files) {
            $selected = self::normalizeExtractList($files);
            if (null === $selected) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

                return false;
            }
        }

        foreach ($state->entries as $zipEntry) {
            if (null !== $selected && !in_array($zipEntry['name'], $selected, true)) {
                continue;
            }
            $target = rtrim($pathto, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $zipEntry['name']);
            $dir = dirname($target);
            if (!VmStatPath::isDir($dir) && !VmFsDirNative::mkdir($dir, 0777, true) && !VmStatPath::isDir($dir)) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_OPEN);

                return false;
            }
            if (false === VmFsWriteNative::write($target, $zipEntry['data'])) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_WRITE);

                return false;
            }
        }

        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    public static function getStatusString(ObjectEntry $entry): string
    {
        $state = self::state($entry);

        return ZipArchiveConstants::statusString($state->status);
    }

    private static function setStatus(ObjectEntry $entry, ZipArchiveState $state, int $code): void
    {
        $state->status = $code;
        self::syncProperties($entry, $state);
    }

    private static function syncProperties(ObjectEntry $entry, ZipArchiveState $state): void
    {
        $entry->getProperty(self::PROP_STATUS)->int($state->status);
        $entry->getProperty(self::PROP_FILENAME)->string($state->filename);
        $entry->getProperty(self::PROP_NUM_FILES)->int(count($state->entries));
    }

    /** @return list<string>|null */
    private static function normalizeExtractList(Variable $files): ?array
    {
        $files = $files->resolveIndirect();
        if (Variable::TYPE_STRING === $files->type) {
            return [$files->toString()];
        }
        if (Variable::TYPE_ARRAY !== $files->type) {
            return null;
        }
        $result = [];
        foreach ($files->toArray()->iterateKeyed(true) as [, $nameVar]) {
            if (!$nameVar instanceof Variable) {
                return null;
            }
            $nameVar = $nameVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $nameVar->type) {
                return null;
            }
            $result[] = $nameVar->toString();
        }

        return $result;
    }

    private static function crc32Unsigned(string $data): int
    {
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 0x100000000;
        }

        return (int) $crc;
    }

    private static function methodDisplayName(string $lc): string
    {
        return match ($lc) {
            'addfile' => 'addFile',
            'addfromstring' => 'addFromString',
            'getfromname' => 'getFromName',
            'extractto' => 'extractTo',
            'getstatusstring' => 'getStatusString',
            default => $lc,
        };
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }
}
