<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsDirNative;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmFsWriteNative;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPCompiler\ext\standard\VmStatPath;
use PHPCompiler\ext\standard\VmStreamContext;
use PHPCompiler\VM\Variable;

/**
 * ZipArchive VM implementation in PHP (php-src ext/zip/php_zip.c; issues #6413, #6414).
 */
final class VmZipArchive
{
    public const CLASS_LC = 'ziparchive';

    public const PROP_STATUS = 'status';

    public const PROP_STATUS_SYS = 'statusSys';

    public const PROP_LAST_ID = 'lastId';

    public const PROP_FILENAME = 'filename';

    public const PROP_NUM_FILES = 'numFiles';

    /** Archive EOCD comment — mirrors getArchiveComment() when set; '' when unset (#20584). */
    public const PROP_COMMENT = 'comment';

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
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;

        $entry = new ClassEntry('ZipArchive');
        $entry->isInternal = true;
        $entry->properties[] = new ClassProperty(self::PROP_STATUS, null, $intProto, false, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_STATUS_SYS, null, $intProto, false, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_LAST_ID, null, $intProto, false, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_FILENAME, null, $strProto, false, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_NUM_FILES, null, $intProto, false, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_COMMENT, null, $strProto, false, $pub, self::CLASS_LC);
        // php-src ext/zip/php_zip.c — ZipArchive implements Countable (#19492)
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }

        // Declared casing is the storage key (ClassConstName / #25929). Map keys in
        // ZipArchiveConstants::CLASS_CONSTANTS are lowercase legacy labels; use
        // CLASS_CONSTANT_NAMES so ZipArchive::CREATE / defined() resolve (#28110).
        foreach (ZipArchiveConstants::CLASS_CONSTANTS as $name => $value) {
            if (\is_string($value)) {
                $const = new Variable(Variable::TYPE_STRING);
                $const->string($value);
            } else {
                $const = new Variable(Variable::TYPE_INTEGER);
                $const->int($value);
            }
            $canonical = ZipArchiveConstants::CLASS_CONSTANT_NAMES[$name];
            $entry->constants[$canonical] = $const;
            $entry->constNames[$canonical] = $canonical;
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
            'count' => new ZipArchiveCount(),
            'statname' => new ZipArchiveStatName(),
            'setpassword' => new ZipArchiveSetPassword(),
            'setencryptionname' => new ZipArchiveSetEncryptionName(),
            // Index / mutation APIs — php-src php_zip.c (#19880)
            'statindex' => new ZipArchiveStatIndex(),
            'locatename' => new ZipArchiveLocateName(),
            'getfromindex' => new ZipArchiveGetFromIndex(),
            'getnameindex' => new ZipArchiveGetNameIndex(),
            'deletename' => new ZipArchiveDeleteName(),
            'deleteindex' => new ZipArchiveDeleteIndex(),
            'addemptydir' => new ZipArchiveAddEmptyDir(),
            'renamename' => new ZipArchiveRenameName(),
            'renameindex' => new ZipArchiveRenameIndex(),
            'getstream' => new ZipArchiveGetStream(),
            // mtime / external attributes / compression — php-src php_zip.c (#20363)
            'setmtimename' => new ZipArchiveSetMtimeName(),
            'setmtimeindex' => new ZipArchiveSetMtimeIndex(),
            'setexternalattributesname' => new ZipArchiveSetExternalAttributesName(),
            'setexternalattributesindex' => new ZipArchiveSetExternalAttributesIndex(),
            'getexternalattributesname' => new ZipArchiveGetExternalAttributesName(),
            'getexternalattributesindex' => new ZipArchiveGetExternalAttributesIndex(),
            'setcompressionname' => new ZipArchiveSetCompressionName(),
            'setcompressionindex' => new ZipArchiveSetCompressionIndex(),
            'iscompressionmethodsupported' => new ZipArchiveIsCompressionMethodSupported(),
            // encryption capability / callbacks / streams / clearError (#20378)
            'isencryptionmethodsupported' => new ZipArchiveIsEncryptionMethodSupported(),
            'registerprogresscallback' => new ZipArchiveRegisterProgressCallback(),
            'registercancelcallback' => new ZipArchiveRegisterCancelCallback(),
            'getstreamindex' => new ZipArchiveGetStreamIndex(),
            'getstreamname' => new ZipArchiveGetStreamName(),
            'clearerror' => new ZipArchiveClearError(),
            'setencryptionindex' => new ZipArchiveSetEncryptionIndex(),
            // entry / archive comments — php-src php_zip.c (#20386)
            'setcommentname' => new ZipArchiveSetCommentName(),
            'setcommentindex' => new ZipArchiveSetCommentIndex(),
            'getcommentname' => new ZipArchiveGetCommentName(),
            'getcommentindex' => new ZipArchiveGetCommentIndex(),
            'setarchivecomment' => new ZipArchiveSetArchiveComment(),
            'getarchivecomment' => new ZipArchiveGetArchiveComment(),
            // unchange / replace / bulk-add — php-src php_zip.c (#20387)
            'unchangeall' => new ZipArchiveUnchangeAll(),
            'unchangearchive' => new ZipArchiveUnchangeArchive(),
            'unchangeindex' => new ZipArchiveUnchangeIndex(),
            'unchangename' => new ZipArchiveUnchangeName(),
            'replacefile' => new ZipArchiveReplaceFile(),
            'addglob' => new ZipArchiveAddGlob(),
            'addpattern' => new ZipArchiveAddPattern(),
            // read-only archive flag — php-src php_zip.c (#20412)
            'iswritable' => new ZipArchiveIsWritable(),
            'setreadonly' => new ZipArchiveSetReadOnly(),
            // archive flags — php-src php_zip.c (#21831)
            'setarchiveflag' => new ZipArchiveSetArchiveFlag(),
            'getarchiveflag' => new ZipArchiveGetArchiveFlag(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = \in_array($name, [
                'iscompressionmethodsupported',
                'isencryptionmethodsupported',
            ], true) ? $pubStatic : $pub;
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
            $state->archiveComment = '';
        } elseif ($flags & ZipArchiveConstants::OVERWRITE) {
            $state->entries = [];
            $state->archiveComment = '';
        } else {
            $read = ZipEngine::readArchive($filename);
            if (!$read['ok']) {
                self::setStatus($entry, $state, $read['code']);

                return $read['code'];
            }
            $state->entries = $read['entries'];
            $state->archiveComment = $read['comment'];
        }

        // Tag entries with orig_index so unchange* can restore by open-time slot (#20387).
        foreach ($state->entries as $i => $zipEntry) {
            $state->entries[$i]['orig_index'] = $i;
        }
        $state->openSnapshot = self::cloneEntries($state->entries);
        $state->openSnapshotComment = $state->archiveComment;
        $state->archiveFlags = 0;
        $state->openSnapshotArchiveFlags = 0;

        $state->filename = $filename;
        $state->open = true;
        $state->dirty = false;
        $state->readOnly = false;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    public static function close(ObjectEntry $entry, ?Context $ctx = null): bool
    {
        $state = self::state($entry);
        if (!$state->open) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        if (null !== $ctx && self::shouldCancel($entry, $ctx)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        // setReadOnly / AFL_RDONLY — refuse persisting mutations (#20412).
        if (!$state->readOnly && ($state->dirty || $state->open)) {
            if (!ZipEngine::writeArchive($state->filename, $state->entries, $state->archiveComment)) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_WRITE);

                return false;
            }
        }
        $state->open = false;
        $state->dirty = false;
        $state->readOnly = false;
        $state->archiveFlags = 0;
        $state->openSnapshotArchiveFlags = 0;
        $state->archiveComment = '';
        $state->openSnapshot = [];
        $state->openSnapshotComment = '';
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);
        if (null !== $ctx) {
            self::fireProgress($entry, $ctx, 1.0);
        }

        return true;
    }

    public static function addFile(ObjectEntry $entry, string $filepath, string $entryname = ''): bool
    {
        $state = self::state($entry);
        if (!$state->open) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_ZIPCLOSED);

            return false;
        }
        if (!self::requireWritable($entry, $state)) {
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
        $row = self::makeEntry($entryname, $data, $crc, $size);
        foreach ($state->entries as $idx => $existing) {
            if ($existing['name'] === $entryname) {
                $row['orig_index'] = $existing['orig_index'] ?? null;
                $state->entries[$idx] = $row;
                $state->dirty = true;
                self::noteLastId($state, $idx);
                self::syncProperties($entry, $state);
                self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

                return true;
            }
        }
        $row['orig_index'] = null;
        $state->entries[] = $row;
        $state->dirty = true;
        self::noteLastId($state, \count($state->entries) - 1);
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
        if (!self::requireWritable($entry, $state)) {
            return false;
        }
        $size = strlen($content);
        $crc = self::crc32Unsigned($content);
        $row = self::makeEntry($name, $content, $crc, $size);
        foreach ($state->entries as $idx => $existing) {
            if ($existing['name'] === $name) {
                $row['orig_index'] = $existing['orig_index'] ?? null;
                $state->entries[$idx] = $row;
                $state->dirty = true;
                self::noteLastId($state, $idx);
                self::syncProperties($entry, $state);
                self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

                return true;
            }
        }
        $row['orig_index'] = null;
        $state->entries[] = $row;
        $state->dirty = true;
        self::noteLastId($state, \count($state->entries) - 1);
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

    /**
     * ZipArchive::statName — php-src RETURN_SB shape (#19873).
     *
     * @return array{
     *     name: string,
     *     index: int,
     *     crc: int,
     *     size: int,
     *     mtime: int,
     *     comp_size: int,
     *     comp_method: int,
     *     encryption_method: int
     * }|false
     */
    public static function statName(ObjectEntry $entry, string $name, int $flags = 0): array|false
    {
        unset($flags); // FL_* lookup flags not yet implemented; exact name match only
        $state = self::requireOpen($entry);
        if ('' === $name) {
            throw new \ValueError('ZipArchive::statName(): Argument #1 ($name) must not be empty');
        }
        foreach ($state->entries as $index => $zipEntry) {
            if ($zipEntry['name'] === $name) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

                return self::statBag($zipEntry, $index);
            }
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

        return false;
    }

    /**
     * ZipArchive::statIndex — php-src zim_ZipArchive_statIndex (#19880).
     *
     * @return array{
     *     name: string,
     *     index: int,
     *     crc: int,
     *     size: int,
     *     mtime: int,
     *     comp_size: int,
     *     comp_method: int,
     *     encryption_method: int
     * }|false
     */
    public static function statIndex(ObjectEntry $entry, int $index, int $flags = 0): array|false
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return self::statBag($state->entries[$index], $index);
    }

    /**
     * ZipArchive::locateName — php-src zim_ZipArchive_locateName (#19880).
     *
     * @return int|false
     */
    public static function locateName(ObjectEntry $entry, string $name, int $flags = 0): int|false
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ('' === $name) {
            return false;
        }
        foreach ($state->entries as $index => $zipEntry) {
            if ($zipEntry['name'] === $name) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

                return $index;
            }
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

        return false;
    }

    /**
     * ZipArchive::getNameIndex — php-src zim_ZipArchive_getNameIndex (#19880).
     *
     * @return string|false
     */
    public static function getNameIndex(ObjectEntry $entry, int $index, int $flags = 0): string|false
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return $state->entries[$index]['name'];
    }

    /**
     * ZipArchive::getFromIndex — php-src php_zip_get_from(type=0) (#19880).
     *
     * @return string|false
     */
    public static function getFromIndex(ObjectEntry $entry, int $index, int $len = 0, int $flags = 0): string|false
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        $data = $state->entries[$index]['data'];
        $size = \strlen($data);
        if ($size < 1) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

            return '';
        }
        if ($len < 1) {
            $len = $size;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return \substr($data, 0, $len);
    }

    /**
     * ZipArchive::deleteIndex — php-src zim_ZipArchive_deleteIndex (#19880).
     */
    public static function deleteIndex(ObjectEntry $entry, int $index): bool
    {
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        \array_splice($state->entries, $index, 1);
        $state->dirty = true;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::deleteName — php-src zim_ZipArchive_deleteName (#19880).
     */
    public static function deleteName(ObjectEntry $entry, string $name): bool
    {
        $state = self::requireOpen($entry);
        if ('' === $name) {
            return false;
        }
        foreach ($state->entries as $index => $zipEntry) {
            if ($zipEntry['name'] !== $name) {
                continue;
            }
            \array_splice($state->entries, $index, 1);
            $state->dirty = true;
            self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

            return true;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

        return false;
    }

    /**
     * ZipArchive::addEmptyDir — php-src zim_ZipArchive_addEmptyDir (#19880).
     */
    public static function addEmptyDir(ObjectEntry $entry, string $dirname, int $flags = 0): bool
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ('' === $dirname) {
            return false;
        }
        if (!\str_ends_with($dirname, '/')) {
            $dirname .= '/';
        }
        foreach ($state->entries as $existing) {
            if ($existing['name'] === $dirname) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_EXISTS);

                return false;
            }
        }
        $state->entries[] = self::makeEntry($dirname, '', self::crc32Unsigned(''), 0);
        $state->entries[\count($state->entries) - 1]['orig_index'] = null;
        $state->dirty = true;
        self::noteLastId($state, \count($state->entries) - 1);
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::renameIndex — php-src zim_ZipArchive_renameIndex (#19880).
     */
    public static function renameIndex(ObjectEntry $entry, int $index, string $newName): bool
    {
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            return false;
        }
        if ('' === $newName) {
            throw new \ValueError('ZipArchive::renameIndex(): Argument #2 ($new_name) must not be empty');
        }
        $state->entries[$index]['name'] = $newName;
        $state->dirty = true;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::renameName — php-src zim_ZipArchive_renameName (#19880).
     */
    public static function renameName(ObjectEntry $entry, string $name, string $newName): bool
    {
        $state = self::requireOpen($entry);
        if ('' === $newName) {
            throw new \ValueError('ZipArchive::renameName(): Argument #2 ($new_name) must not be empty');
        }
        foreach ($state->entries as $index => $zipEntry) {
            if ($zipEntry['name'] !== $name) {
                continue;
            }
            $state->entries[$index]['name'] = $newName;
            $state->dirty = true;
            self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

            return true;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

        return false;
    }

    /**
     * ZipArchive::getStream — php-src zim_ZipArchive_getStream (#19880).
     *
     * Returns a readable php://memory stream handle id preloaded with entry bytes.
     *
     * @return int|false
     */
    public static function getStream(ObjectEntry $entry, string $name): int|false
    {
        $state = self::requireOpen($entry);
        if ('' === $name) {
            return false;
        }
        foreach ($state->entries as $zipEntry) {
            if ($zipEntry['name'] !== $name) {
                continue;
            }
            // Directory entries are not readable streams (libzip zip_fopen fails).
            if (\str_ends_with($zipEntry['name'], '/')) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

                return false;
            }
            $handle = VmPhpMemoryStream::openWithBuffer('php://memory', $zipEntry['data'], 'rb');
            if (false === $handle) {
                self::setStatus($entry, $state, ZipArchiveConstants::ER_MEMORY);

                return false;
            }
            VmStreamContext::ensureDefaultForStreamOpen();
            VmFs::registerStreamMode($handle, 'rb');
            self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

            return $handle;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

        return false;
    }

    /** ZipArchive::setPassword — php-src zim_ZipArchive_setPassword (#19873). */
    public static function setPassword(ObjectEntry $entry, string $password): bool
    {
        $state = self::requireOpen($entry);
        if ('' === $password) {
            return false;
        }
        $state->password = $password;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::setEncryptionName — marks entry encryption metadata (#19873).
     *
     * Pure-PHP ZipEngine still stores plaintext; method/password are retained for
     * API/stat parity. Real AES write needs libzip (follow-up).
     */
    public static function setEncryptionName(
        ObjectEntry $entry,
        string $name,
        int $method,
        ?string $password = null
    ): bool {
        $state = self::requireOpen($entry);
        if ('' === $name) {
            throw new \ValueError('ZipArchive::setEncryptionName(): Argument #1 ($name) must not be empty');
        }
        foreach ($state->entries as $idx => $zipEntry) {
            if ($zipEntry['name'] !== $name) {
                continue;
            }
            if (ZipArchiveConstants::EM_NONE === $method) {
                $zipEntry['encryption_method'] = ZipArchiveConstants::EM_NONE;
                unset($zipEntry['encryption_password']);
            } else {
                $zipEntry['encryption_method'] = $method;
                $usePassword = $password ?? $state->password;
                if ('' !== $usePassword) {
                    $zipEntry['encryption_password'] = $usePassword;
                } else {
                    unset($zipEntry['encryption_password']);
                }
            }
            $state->entries[$idx] = $zipEntry;
            $state->dirty = true;
            self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

            return true;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

        return false;
    }

    /**
     * ZipArchive::setEncryptionIndex — php-src zim_ZipArchive_setEncryptionIndex (#20378).
     */
    public static function setEncryptionIndex(
        ObjectEntry $entry,
        int $index,
        int $method,
        ?string $password = null
    ): bool {
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        $name = $state->entries[$index]['name'];

        return self::setEncryptionName($entry, $name, $method, $password);
    }

    /**
     * ZipArchive::isEncryptionMethodSupported — php-src zim_ZipArchive_isEncryptionMethodSupported (#20378).
     *
     * Pure-PHP ZipEngine accepts EM_NONE + AES/PKWARE metadata (setEncryption*); unknown → false.
     */
    public static function isEncryptionMethodSupported(int $method, bool $enc = true): bool
    {
        unset($enc);

        return match ($method) {
            ZipArchiveConstants::EM_NONE,
            ZipArchiveConstants::EM_TRAD_PKWARE,
            ZipArchiveConstants::EM_AES_128,
            ZipArchiveConstants::EM_AES_192,
            ZipArchiveConstants::EM_AES_256 => true,
            default => false,
        };
    }

    /**
     * ZipArchive::clearError — php-src zim_ZipArchive_clearError (#20378).
     */
    public static function clearError(ObjectEntry $entry): void
    {
        $state = self::state($entry);
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);
    }

    /**
     * ZipArchive::getStreamIndex — php-src zim_ZipArchive_getStreamIndex (#20378).
     *
     * @return int|false
     */
    public static function getStreamIndex(ObjectEntry $entry, int $index, int $flags = 0): int|false
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        $name = $state->entries[$index]['name'];

        return self::getStream($entry, $name);
    }

    /**
     * ZipArchive::getStreamName — php-src zim_ZipArchive_getStreamName (#20378).
     *
     * @return int|false
     */
    public static function getStreamName(ObjectEntry $entry, string $name, int $flags = 0): int|false
    {
        unset($flags);

        return self::getStream($entry, $name);
    }

    /**
     * ZipArchive::registerProgressCallback — stores callable; fired at end of mutating ops (#20378).
     */
    public static function registerProgressCallback(ObjectEntry $entry, float $rate, Variable $callback): bool
    {
        $state = self::requireOpen($entry);
        $cb = new Variable();
        $cb->copyFrom($callback->resolveIndirect());
        $state->progressCallback = $cb;
        $state->progressRate = $rate;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::registerCancelCallback — stores callable; non-zero return aborts close (#20378).
     */
    public static function registerCancelCallback(ObjectEntry $entry, Variable $callback): bool
    {
        $state = self::requireOpen($entry);
        $cb = new Variable();
        $cb->copyFrom($callback->resolveIndirect());
        $state->cancelCallback = $cb;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /** Invoke progress callback with state in [0,1] when registered. */
    public static function fireProgress(ObjectEntry $entry, Context $ctx, float $progress): void
    {
        $state = self::state($entry);
        if (null === $state->progressCallback) {
            return;
        }
        $arg = new Variable(Variable::TYPE_FLOAT);
        $arg->float($progress);
        VmCallable::invokeAs('ZipArchive::registerProgressCallback', $ctx, $state->progressCallback, $arg);
    }

    /**
     * Invoke cancel callback; true means abort the operation (php-src non-zero return).
     */
    public static function shouldCancel(ObjectEntry $entry, Context $ctx): bool
    {
        $state = self::state($entry);
        if (null === $state->cancelCallback) {
            return false;
        }
        $result = VmCallable::invokeAs(
            'ZipArchive::registerCancelCallback',
            $ctx,
            $state->cancelCallback
        );
        $result = $result->resolveIndirect();
        if (Variable::TYPE_INTEGER === $result->type) {
            return 0 !== $result->toInt();
        }
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result->toBool();
        }

        return false;
    }

    /**
     * ZipArchive::setCommentName — php-src zim_ZipArchive_setCommentName (#20386).
     */
    public static function setCommentName(ObjectEntry $entry, string $name, string $comment): bool
    {
        $state = self::requireOpen($entry);
        if ('' === $name) {
            throw new \ValueError('ZipArchive::setCommentName(): Argument #1 ($name) must not be empty');
        }
        if (strlen($comment) > 0xffff) {
            throw new \ValueError('ZipArchive::setCommentName(): Argument #2 ($comment) must be less than 65535 bytes');
        }
        $index = self::locateEntryIndex($state, $name);
        if (null === $index) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

            return false;
        }

        return self::setCommentIndex($entry, $index, $comment);
    }

    /**
     * ZipArchive::setCommentIndex — php-src zim_ZipArchive_setCommentIndex (#20386).
     */
    public static function setCommentIndex(ObjectEntry $entry, int $index, string $comment): bool
    {
        $state = self::requireOpen($entry);
        if (strlen($comment) > 0xffff) {
            throw new \ValueError('ZipArchive::setCommentIndex(): Argument #2 ($comment) must be less than 65535 bytes');
        }
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        if ('' === $comment) {
            unset($state->entries[$index]['comment']);
        } else {
            $state->entries[$index]['comment'] = $comment;
        }
        $state->dirty = true;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::getCommentName — php-src zim_ZipArchive_getCommentName (#20386).
     */
    public static function getCommentName(ObjectEntry $entry, string $name, int $flags = 0): string|false
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ('' === $name) {
            throw new \ValueError('ZipArchive::getCommentName(): Argument #1 ($name) must not be empty');
        }
        $index = self::locateEntryIndex($state, $name);
        if (null === $index) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

            return false;
        }

        return self::getCommentIndex($entry, $index, 0);
    }

    /**
     * ZipArchive::getCommentIndex — php-src zim_ZipArchive_getCommentIndex (#20386).
     */
    public static function getCommentIndex(ObjectEntry $entry, int $index, int $flags = 0): string|false
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return (string) ($state->entries[$index]['comment'] ?? '');
    }

    /**
     * ZipArchive::setArchiveComment — php-src zim_ZipArchive_setArchiveComment (#20386).
     */
    public static function setArchiveComment(ObjectEntry $entry, string $comment): bool
    {
        $state = self::requireOpen($entry);
        if (strlen($comment) > 0xffff) {
            throw new \ValueError('ZipArchive::setArchiveComment(): Argument #1 ($comment) must be less than 65535 bytes');
        }
        $state->archiveComment = $comment;
        $state->dirty = true;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::getArchiveComment — php-src zim_ZipArchive_getArchiveComment (#20386).
     *
     * Returns false when no archive comment is set (libzip NULL → false).
     */
    public static function getArchiveComment(ObjectEntry $entry, int $flags = 0): string|false
    {
        unset($flags);
        $state = self::requireOpen($entry);
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);
        if ('' === $state->archiveComment) {
            return false;
        }

        return $state->archiveComment;
    }

    /**
     * ZipArchive::unchangeAll — restore open-time entries + archive comment (#20387).
     */
    public static function unchangeAll(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry);
        $state->entries = self::cloneEntries($state->openSnapshot);
        $state->archiveComment = $state->openSnapshotComment;
        $state->dirty = false;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::unchangeArchive — revert archive comment only (#20387 / zip_unchange_archive).
     */
    public static function unchangeArchive(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry);
        $state->archiveComment = $state->openSnapshotComment;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::unchangeIndex — revert entry at index (#20387 / zip_unchange).
     *
     * Honest subset: restore from open snapshot via orig_index; newly added entries are removed.
     */
    public static function unchangeIndex(ObjectEntry $entry, int $index): bool
    {
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            return false;
        }
        $orig = $state->entries[$index]['orig_index'] ?? null;
        if (null === $orig) {
            \array_splice($state->entries, $index, 1);
            $state->dirty = self::entriesDifferFromSnapshot($state);
            self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

            return true;
        }
        if (!isset($state->openSnapshot[$orig])) {
            return false;
        }
        $restored = $state->openSnapshot[$orig];
        $restored['orig_index'] = $orig;
        $state->entries[$index] = $restored;
        $state->dirty = self::entriesDifferFromSnapshot($state);
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::unchangeName — revert entry by current name (#20387).
     */
    public static function unchangeName(ObjectEntry $entry, string $name): bool
    {
        $state = self::requireOpen($entry);
        if ('' === $name) {
            return false;
        }
        foreach ($state->entries as $index => $zipEntry) {
            if ($zipEntry['name'] !== $name) {
                continue;
            }

            return self::unchangeIndex($entry, $index);
        }

        return false;
    }

    /**
     * ZipArchive::replaceFile — replace entry at index with file bytes (#20387 / php_zip_add_file replace).
     *
     * @param int $length 0 / LENGTH_TO_END = remaining bytes from $start
     */
    public static function replaceFile(
        ObjectEntry $entry,
        string $filepath,
        int $index,
        int $start = 0,
        int $length = 0,
        int $flags = 0
    ): bool {
        unset($flags);
        $state = self::requireOpen($entry);
        if ('' === $filepath) {
            throw new \ValueError('ZipArchive::replaceFile(): Argument #1 ($filepath) must not be empty');
        }
        if ($index < 0) {
            throw new \ValueError('ZipArchive::replaceFile(): Argument #2 ($index) must be greater than or equal to 0');
        }
        if ($index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
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
        if ($start < 0) {
            $start = 0;
        }
        if ($start > \strlen($data)) {
            $data = '';
        } elseif ($length <= 0) {
            $data = \substr($data, $start);
        } else {
            $data = \substr($data, $start, $length);
        }
        $name = $state->entries[$index]['name'];
        $orig = $state->entries[$index]['orig_index'] ?? null;
        $row = self::makeEntry($name, $data, self::crc32Unsigned($data), \strlen($data));
        $row['orig_index'] = $orig;
        $state->entries[$index] = $row;
        $state->dirty = true;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::addGlob — glob pattern → addFile each match (#20387 / php_zip_add_from_pattern type=1).
     *
     * Honest subset: options remove_all_path / remove_path / add_path supported; FL_* deferred.
     *
     * @param array<string, mixed> $options
     * @return list<string>|false
     */
    public static function addGlob(ObjectEntry $entry, string $pattern, int $flags = 0, array $options = []): array|false
    {
        $state = self::requireOpen($entry);
        if ('' === $pattern) {
            throw new \ValueError('ZipArchive::addGlob(): Argument #1 ($pattern) must not be empty');
        }
        $found = \glob($pattern, $flags);
        if (false === $found) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        if ([] === $found) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

            return [];
        }

        return self::addFilesFromPaths($entry, $found, $options);
    }

    /**
     * ZipArchive::addPattern — PCRE filter under $path → addFile (#20387 / php_zip_add_from_pattern type=2).
     *
     * @param array<string, mixed> $options
     * @return list<string>|false
     */
    public static function addPattern(
        ObjectEntry $entry,
        string $pattern,
        string $path = '.',
        array $options = []
    ): array|false {
        $state = self::requireOpen($entry);
        if ('' === $pattern) {
            throw new \ValueError('ZipArchive::addPattern(): Argument #1 ($pattern) must not be empty');
        }
        if (!is_dir($path)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

            return false;
        }
        $found = [];
        $dh = @opendir($path);
        if (false === $dh) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_READ);

            return false;
        }
        while (false !== ($file = readdir($dh))) {
            if ('.' === $file || '..' === $file) {
                continue;
            }
            $full = rtrim($path, "/\\") . DIRECTORY_SEPARATOR . $file;
            if (!is_file($full)) {
                continue;
            }
            $match = @preg_match($pattern, $file);
            if (1 === $match) {
                $found[] = $full;
            } elseif (false === $match) {
                closedir($dh);
                self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

                return false;
            }
        }
        closedir($dh);
        if ([] === $found) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

            return [];
        }

        return self::addFilesFromPaths($entry, $found, $options);
    }

    /**
     * @param list<string> $paths
     * @param array<string, mixed> $options
     * @return list<string>|false
     */
    private static function addFilesFromPaths(ObjectEntry $entry, array $paths, array $options): array|false
    {
        $added = [];
        foreach ($paths as $filepath) {
            if (!is_file($filepath)) {
                continue;
            }
            $entryName = self::entryNameFromOptions($filepath, $options);
            if (!self::addFile($entry, $filepath, $entryName)) {
                return false;
            }
            $added[] = $filepath;
        }
        self::setStatus($entry, self::state($entry), ZipArchiveConstants::ER_OK);

        return $added;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function entryNameFromOptions(string $filepath, array $options): string
    {
        $removeAll = !empty($options['remove_all_path']);
        $removePath = isset($options['remove_path']) ? (string) $options['remove_path'] : '';
        $addPath = isset($options['add_path']) ? (string) $options['add_path'] : '';

        if ($removeAll) {
            $stripped = basename($filepath);
        } elseif ('' !== $removePath && str_starts_with($filepath, $removePath)) {
            $rest = substr($filepath, strlen($removePath));
            if (isset($rest[0]) && ('/' === $rest[0] || '\\' === $rest[0])) {
                $rest = substr($rest, 1);
            }
            $stripped = $rest;
        } else {
            $stripped = $filepath;
        }

        return $addPath . $stripped;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    private static function cloneEntries(array $entries): array
    {
        $out = [];
        foreach ($entries as $row) {
            $out[] = $row;
        }

        return $out;
    }

    private static function entriesDifferFromSnapshot(ZipArchiveState $state): bool
    {
        if ($state->archiveComment !== $state->openSnapshotComment) {
            return true;
        }
        if (\count($state->entries) !== \count($state->openSnapshot)) {
            return true;
        }
        foreach ($state->entries as $i => $row) {
            $snap = $state->openSnapshot[$i] ?? null;
            if (null === $snap) {
                return true;
            }
            if (($row['name'] ?? '') !== ($snap['name'] ?? '')
                || ($row['data'] ?? '') !== ($snap['data'] ?? '')
                || ($row['crc'] ?? 0) !== ($snap['crc'] ?? 0)
                || ($row['comment'] ?? '') !== ($snap['comment'] ?? '')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * ZipArchive::setMtimeName — php-src zim_ZipArchive_setMtimeName (#20363).
     */
    public static function setMtimeName(ObjectEntry $entry, string $name, int $timestamp, int $flags = 0): bool
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ('' === $name) {
            throw new \ValueError('ZipArchive::setMtimeName(): Argument #1 ($name) must not be empty');
        }
        $index = self::locateEntryIndex($state, $name);
        if (null === $index) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

            return false;
        }

        return self::setMtimeIndex($entry, $index, $timestamp, 0);
    }

    /**
     * ZipArchive::setMtimeIndex — php-src zim_ZipArchive_setMtimeIndex (#20363).
     */
    public static function setMtimeIndex(ObjectEntry $entry, int $index, int $timestamp, int $flags = 0): bool
    {
        unset($flags);
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        $state->entries[$index]['mtime'] = $timestamp;
        $state->dirty = true;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::setExternalAttributesName — php-src zim_ZipArchive_setExternalAttributesName (#20363).
     */
    public static function setExternalAttributesName(
        ObjectEntry $entry,
        string $name,
        int $opsys,
        int $attr,
        int $flags = 0
    ): bool {
        unset($flags);
        $state = self::requireOpen($entry);
        if ('' === $name) {
            throw new \ValueError('ZipArchive::setExternalAttributesName(): Argument #1 ($name) must not be empty');
        }
        $index = self::locateEntryIndex($state, $name);
        if (null === $index) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

            return false;
        }

        return self::setExternalAttributesIndex($entry, $index, $opsys, $attr, 0);
    }

    /**
     * ZipArchive::setExternalAttributesIndex — php-src zim_ZipArchive_setExternalAttributesIndex (#20363).
     */
    public static function setExternalAttributesIndex(
        ObjectEntry $entry,
        int $index,
        int $opsys,
        int $attr,
        int $flags = 0
    ): bool {
        unset($flags);
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        $state->entries[$index]['opsys'] = $opsys & 0xff;
        $state->entries[$index]['external_attr'] = $attr & 0xffffffff;
        $state->dirty = true;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::getExternalAttributesName — php-src zim_ZipArchive_getExternalAttributesName (#20363).
     *
     * @return array{opsys: int, attr: int}|false
     */
    public static function getExternalAttributesName(
        ObjectEntry $entry,
        string $name,
        int $flags = 0
    ): array|false {
        unset($flags);
        $state = self::requireOpen($entry);
        if ('' === $name) {
            throw new \ValueError('ZipArchive::getExternalAttributesName(): Argument #1 ($name) must not be empty');
        }
        $index = self::locateEntryIndex($state, $name);
        if (null === $index) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

            return false;
        }

        return self::getExternalAttributesIndex($entry, $index, 0);
    }

    /**
     * ZipArchive::getExternalAttributesIndex — php-src zim_ZipArchive_getExternalAttributesIndex (#20363).
     *
     * @return array{opsys: int, attr: int}|false
     */
    public static function getExternalAttributesIndex(
        ObjectEntry $entry,
        int $index,
        int $flags = 0
    ): array|false {
        unset($flags);
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        $zipEntry = $state->entries[$index];
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return [
            'opsys' => (int) ($zipEntry['opsys'] ?? ZipArchiveConstants::OPSYS_DEFAULT),
            'attr' => (int) ($zipEntry['external_attr'] ?? 0),
        ];
    }

    /**
     * ZipArchive::setCompressionName — php-src zim_ZipArchive_setCompressionName (#20363).
     *
     * Pure-PHP ZipEngine writes stored (CM_STORE) payloads; method is retained for API/stat
     * parity. Unsupported encode methods return false (libzip zip_set_file_compression).
     */
    public static function setCompressionName(
        ObjectEntry $entry,
        string $name,
        int $method,
        int $compflags = 0
    ): bool {
        unset($compflags);
        $state = self::requireOpen($entry);
        if ('' === $name) {
            throw new \ValueError('ZipArchive::setCompressionName(): Argument #1 ($name) must not be empty');
        }
        $index = self::locateEntryIndex($state, $name);
        if (null === $index) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_NOENT);

            return false;
        }

        return self::setCompressionIndex($entry, $index, $method, 0);
    }

    /**
     * ZipArchive::setCompressionIndex — php-src zim_ZipArchive_setCompressionIndex (#20363).
     */
    public static function setCompressionIndex(
        ObjectEntry $entry,
        int $index,
        int $method,
        int $compflags = 0
    ): bool {
        unset($compflags);
        $state = self::requireOpen($entry);
        if ($index < 0 || $index >= \count($state->entries)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        if (!self::isCompressionMethodSupported($method, true)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_COMPNOTSUPP);

            return false;
        }
        $normalized = ZipArchiveConstants::CM_DEFAULT === $method
            ? ZipArchiveConstants::CM_STORE
            : $method;
        $state->entries[$index]['comp_method'] = $normalized;
        $state->dirty = true;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::isCompressionMethodSupported — php-src zim_ZipArchive_isCompressionMethodSupported (#20363).
     *
     * Pure-PHP ZipEngine only stores/unstores CM_STORE (and CM_DEFAULT → store).
     */
    public static function isCompressionMethodSupported(int $method, bool $enc = true): bool
    {
        unset($enc);

        return ZipArchiveConstants::CM_STORE === $method
            || ZipArchiveConstants::CM_DEFAULT === $method;
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

    /** Countable::count() / ZipArchive::count() — php-src php_zip.c (#19492). */
    public static function numFiles(ObjectEntry $entry): int
    {
        return \count(self::state($entry)->entries);
    }

    /** ZIP_FROM_OBJECT — php-src php_zip.c ValueError when archive not open. */
    private static function requireOpen(ObjectEntry $entry): ZipArchiveState
    {
        $state = self::state($entry);
        if (!$state->open) {
            throw new \ValueError('Invalid or uninitialized Zip object');
        }

        return $state;
    }

    /** Fail mutating ops when setReadOnly(true) (php-src ZIP_ER_RDONLY; #20412). */
    private static function requireWritable(ObjectEntry $entry, ZipArchiveState $state): bool
    {
        if ($state->readOnly) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_RDONLY);

            return false;
        }

        return true;
    }

    /**
     * ZipArchive::isWritable — true when archive is open and not read-only (#20412).
     */
    public static function isWritable(ObjectEntry $entry): bool
    {
        $state = self::requireOpen($entry);
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return !$state->readOnly;
    }

    /**
     * ZipArchive::setReadOnly — toggle AFL_RDONLY-style session flag (#20412).
     */
    public static function setReadOnly(ObjectEntry $entry, bool $readonly): bool
    {
        $state = self::requireOpen($entry);
        $state->readOnly = $readonly;
        if ($readonly) {
            $state->archiveFlags |= ZipArchiveConstants::AFL_RDONLY;
        } else {
            $state->archiveFlags &= ~ZipArchiveConstants::AFL_RDONLY;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * Known libzip archive-flag bits we advertise (php-src ZipArchive::AFL_*; #21831).
     */
    private static function isKnownArchiveFlag(int $flag): bool
    {
        return match ($flag) {
            ZipArchiveConstants::AFL_RDONLY,
            ZipArchiveConstants::AFL_IS_TORRENTZIP,
            ZipArchiveConstants::AFL_WANT_TORRENTZIP,
            ZipArchiveConstants::AFL_CREATE_OR_KEEP_FILE_FOR_EMPTY_ARCHIVE => true,
            default => false,
        };
    }

    /**
     * ZipArchive::setArchiveFlag — libzip zip_set_archive_flag (php-src php_zip.c; #21831).
     *
     * AFL_RDONLY once set cannot be cleared (libzip).
     */
    public static function setArchiveFlag(ObjectEntry $entry, int $flag, int $value): bool
    {
        $state = self::requireOpen($entry);
        if (!self::isKnownArchiveFlag($flag)) {
            self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

            return false;
        }
        $enable = 0 !== $value;
        if (ZipArchiveConstants::AFL_RDONLY === $flag) {
            if (!$enable && ($state->archiveFlags & ZipArchiveConstants::AFL_RDONLY)) {
                // libzip: ZIP_AFL_RDONLY — read only -- cannot be cleared
                self::setStatus($entry, $state, ZipArchiveConstants::ER_INVAL);

                return false;
            }
            $state->readOnly = $enable;
        }
        if ($enable) {
            $state->archiveFlags |= $flag;
        } else {
            $state->archiveFlags &= ~$flag;
        }
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return true;
    }

    /**
     * ZipArchive::getArchiveFlag — libzip zip_get_archive_flag (php-src php_zip.c; #21831).
     *
     * Returns 1 when the flag bit is set, else 0. With FL_UNCHANGED, uses open-time flags.
     */
    public static function getArchiveFlag(ObjectEntry $entry, int $flag, int $flags = 0): int
    {
        $state = self::requireOpen($entry);
        $bits = (0 !== ($flags & ZipArchiveConstants::FL_UNCHANGED))
            ? $state->openSnapshotArchiveFlags
            : $state->archiveFlags;
        self::setStatus($entry, $state, ZipArchiveConstants::ER_OK);

        return (0 !== ($bits & $flag)) ? 1 : 0;
    }

    /**
     * @param array{
     *     name: string,
     *     data: string,
     *     crc: int,
     *     size: int,
     *     mtime?: int,
     *     comp_method?: int,
     *     opsys?: int,
     *     external_attr?: int,
     *     encryption_method?: int
     * } $zipEntry
     * @return array{
     *     name: string,
     *     index: int,
     *     crc: int,
     *     size: int,
     *     mtime: int,
     *     comp_size: int,
     *     comp_method: int,
     *     encryption_method: int
     * }
     */
    private static function statBag(array $zipEntry, int $index): array
    {
        $size = (int) $zipEntry['size'];

        return [
            'name' => $zipEntry['name'],
            'index' => $index,
            'crc' => (int) $zipEntry['crc'],
            'size' => $size,
            'mtime' => (int) ($zipEntry['mtime'] ?? 0),
            'comp_size' => $size,
            'comp_method' => (int) ($zipEntry['comp_method'] ?? ZipArchiveConstants::CM_STORE),
            'encryption_method' => (int) ($zipEntry['encryption_method'] ?? ZipArchiveConstants::EM_NONE),
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     data: string,
     *     crc: int,
     *     size: int,
     *     mtime: int,
     *     comp_method: int,
     *     opsys: int,
     *     external_attr: int,
     *     encryption_method: int
     * }
     */
    private static function makeEntry(string $name, string $data, int $crc, int $size): array
    {
        return [
            'name' => $name,
            'data' => $data,
            'crc' => $crc,
            'size' => $size,
            'mtime' => time(),
            'comp_method' => ZipArchiveConstants::CM_STORE,
            'opsys' => ZipArchiveConstants::OPSYS_DEFAULT,
            'external_attr' => 0,
            'encryption_method' => ZipArchiveConstants::EM_NONE,
        ];
    }

    /** @return int|null */
    private static function locateEntryIndex(ZipArchiveState $state, string $name): ?int
    {
        foreach ($state->entries as $index => $zipEntry) {
            if ($zipEntry['name'] === $name) {
                return $index;
            }
        }

        return null;
    }

    private static function setStatus(ObjectEntry $entry, ZipArchiveState $state, int $code): void
    {
        $state->status = $code;
        // Pure-PHP engine: no libzip system errno; keep 0 on OK, leave prior on soft failures.
        if (ZipArchiveConstants::ER_OK === $code) {
            $state->statusSys = 0;
        }
        self::syncProperties($entry, $state);
    }

    private static function syncProperties(ObjectEntry $entry, ZipArchiveState $state): void
    {
        $entry->getProperty(self::PROP_STATUS)->int($state->status);
        $entry->getProperty(self::PROP_STATUS_SYS)->int($state->statusSys);
        $entry->getProperty(self::PROP_LAST_ID)->int($state->lastId);
        $entry->getProperty(self::PROP_FILENAME)->string($state->filename);
        $entry->getProperty(self::PROP_NUM_FILES)->int(count($state->entries));
        // php-src property reader: NULL comment → empty string (unlike getArchiveComment → false).
        $entry->getProperty(self::PROP_COMMENT)->string($state->archiveComment);
    }

    /** Record last successfully added/replaced entry index (php-src last_id). */
    private static function noteLastId(ZipArchiveState $state, int $index): void
    {
        $state->lastId = $index;
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
            'statname' => 'statName',
            'setpassword' => 'setPassword',
            'setencryptionname' => 'setEncryptionName',
            'statindex' => 'statIndex',
            'locatename' => 'locateName',
            'getfromindex' => 'getFromIndex',
            'getnameindex' => 'getNameIndex',
            'deletename' => 'deleteName',
            'deleteindex' => 'deleteIndex',
            'addemptydir' => 'addEmptyDir',
            'renamename' => 'renameName',
            'renameindex' => 'renameIndex',
            'getstream' => 'getStream',
            'setmtimename' => 'setMtimeName',
            'setmtimeindex' => 'setMtimeIndex',
            'setexternalattributesname' => 'setExternalAttributesName',
            'setexternalattributesindex' => 'setExternalAttributesIndex',
            'getexternalattributesname' => 'getExternalAttributesName',
            'getexternalattributesindex' => 'getExternalAttributesIndex',
            'setcompressionname' => 'setCompressionName',
            'setcompressionindex' => 'setCompressionIndex',
            'iscompressionmethodsupported' => 'isCompressionMethodSupported',
            'isencryptionmethodsupported' => 'isEncryptionMethodSupported',
            'registerprogresscallback' => 'registerProgressCallback',
            'registercancelcallback' => 'registerCancelCallback',
            'getstreamindex' => 'getStreamIndex',
            'getstreamname' => 'getStreamName',
            'clearerror' => 'clearError',
            'setencryptionindex' => 'setEncryptionIndex',
            'setcommentname' => 'setCommentName',
            'setcommentindex' => 'setCommentIndex',
            'getcommentname' => 'getCommentName',
            'getcommentindex' => 'getCommentIndex',
            'setarchivecomment' => 'setArchiveComment',
            'getarchivecomment' => 'getArchiveComment',
            'unchangeall' => 'unchangeAll',
            'unchangearchive' => 'unchangeArchive',
            'unchangeindex' => 'unchangeIndex',
            'unchangename' => 'unchangeName',
            'replacefile' => 'replaceFile',
            'addglob' => 'addGlob',
            'addpattern' => 'addPattern',
            'iswritable' => 'isWritable',
            'setreadonly' => 'setReadOnly',
            'setarchiveflag' => 'setArchiveFlag',
            'getarchiveflag' => 'getArchiveFlag',
            default => $lc,
        };
    }

    public static function coerceFloatArg(Variable $var, string $label, int $index, string $paramName): float
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type float, %s given',
                $label,
                $index,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return $var->toFloat();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return (float) $var->toInt();
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type float, %s given',
            $label,
            $index,
            $paramName,
            self::typeLabel($var)
        ));
    }

    public static function coerceBoolArg(Variable $var, string $label, int $index, string $paramName): bool
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type bool, %s given',
                $label,
                $index,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type bool, %s given',
                $label,
                $index,
                $paramName,
                self::typeLabel($var)
            ));
        }

        return $var->toBool();
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
