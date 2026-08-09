<?php

declare(strict_types=1);

namespace PHPCompiler\ext\rar;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmFsWriteNative;
use PHPCompiler\ext\standard\VmString;

/**
 * RarArchive / RarEntry VM implementation (PECL rar; #6237).
 */
final class VmRar
{
    public const ARCHIVE_LC = 'rararchive';

    public const ENTRY_LC = 'rarentry';

    /** @var array<int, array{path: string, entries: list<array{name: string, data: string, crc: int, packed: int, unpacked: int, hostOs: int, method: int, isDir: bool}>, broken: bool, solid: bool, comment: string, closed: bool, allowBroken: bool}> */
    private static array $archives = [];

    /** @var array<int, array{archiveId: int, index: int}> */
    private static array $entries = [];

    public static function registerClasses(Context $ctx): void
    {
        self::registerArchive($ctx);
        self::registerEntry($ctx);
    }

    private static function registerArchive(Context $ctx): void
    {
        if (isset($ctx->classes[self::ARCHIVE_LC]) && isset($ctx->classes[self::ARCHIVE_LC]->methods['open'])) {
            return;
        }

        $entry = isset($ctx->classes[self::ARCHIVE_LC])
            ? $ctx->classes[self::ARCHIVE_LC]
            : new ClassEntry('RarArchive');
        $entry->isInternal = true;
        $entry->isFinal = true;
        if (isset($ctx->classes['traversable'])) {
            $entry->interfaces[] = 'traversable';
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;

        $methods = [
            'open' => [new RarArchiveOpen(), $pubStatic, 'open'],
            'getentries' => [new RarArchiveGetEntries(), $pub, 'getEntries'],
            'getentry' => [new RarArchiveGetEntry(), $pub, 'getEntry'],
            'isbroken' => [new RarArchiveIsBroken(), $pub, 'isBroken'],
            'issolid' => [new RarArchiveIsSolid(), $pub, 'isSolid'],
            'getcomment' => [new RarArchiveGetComment(), $pub, 'getComment'],
            'close' => [new RarArchiveClose(), $pub, 'close'],
            '__tostring' => [new RarArchiveToString(), $pub, '__toString'],
        ];
        foreach ($methods as $lc => [$handler, $vis, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }

        $ctx->classes[self::ARCHIVE_LC] = $entry;
    }

    private static function registerEntry(Context $ctx): void
    {
        if (isset($ctx->classes[self::ENTRY_LC]) && isset($ctx->classes[self::ENTRY_LC]->methods['getname'])) {
            return;
        }

        $entry = isset($ctx->classes[self::ENTRY_LC])
            ? $ctx->classes[self::ENTRY_LC]
            : new ClassEntry('RarEntry');
        $entry->isInternal = true;
        $entry->isFinal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        foreach ([
            'HOST_MSDOS' => 0,
            'HOST_OS2' => 1,
            'HOST_WIN32' => 2,
            'HOST_UNIX' => 3,
            'HOST_MACOS' => 4,
            'HOST_BEOS' => 5,
        ] as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$name] = $const;
            $entry->constNames[$name] = $name;
        }

        $methods = [
            'getname' => [new RarEntryGetName(), 'getName'],
            'getunpackedsize' => [new RarEntryGetUnpackedSize(), 'getUnpackedSize'],
            'getpackedsize' => [new RarEntryGetPackedSize(), 'getPackedSize'],
            'getcrc' => [new RarEntryGetCrc(), 'getCrc'],
            'gethostos' => [new RarEntryGetHostOs(), 'getHostOs'],
            'getmethod' => [new RarEntryGetMethod(), 'getMethod'],
            'isdirectory' => [new RarEntryIsDirectory(), 'isDirectory'],
            'isencrypted' => [new RarEntryIsEncrypted(), 'isEncrypted'],
            'extract' => [new RarEntryExtract(), 'extract'],
            '__tostring' => [new RarEntryToString(), '__toString'],
        ];
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = $name;
        }

        $ctx->classes[self::ENTRY_LC] = $entry;
    }

    public static function open(Context $ctx, string $filename, ?string $password = null): ObjectEntry
    {
        if (null !== $password && '' !== $password) {
            throw new \RarException('Password-protected archives are not supported in this build');
        }
        $parsed = RarEngine::readArchive($filename);
        if (!($parsed['ok'] ?? false)) {
            throw new \RarException((string) ($parsed['message'] ?? 'Failed to open RAR archive'));
        }

        $class = $ctx->classes[self::ARCHIVE_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('RarArchive is not registered');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::$archives[$object->id] = [
            'path' => $filename,
            'entries' => $parsed['entries'],
            'broken' => (bool) $parsed['broken'],
            'solid' => (bool) $parsed['solid'],
            'comment' => (string) $parsed['comment'],
            'closed' => false,
            'allowBroken' => false,
        ];

        return $object;
    }

    public static function requireArchive(Variable $var, string $label): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError($label.' must be called on RarArchive');
        }
        $object = $resolved->toObject();
        if (self::ARCHIVE_LC !== strtolower($object->class->name)) {
            throw new \TypeError($label.' must be called on RarArchive');
        }
        if (!isset(self::$archives[$object->id]) || self::$archives[$object->id]['closed']) {
            throw new \RarException('The archive is already closed');
        }

        return $object;
    }

    public static function requireEntry(Variable $var, string $label): ObjectEntry
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError($label.' must be called on RarEntry');
        }
        $object = $resolved->toObject();
        if (self::ENTRY_LC !== strtolower($object->class->name)) {
            throw new \TypeError($label.' must be called on RarEntry');
        }
        if (!isset(self::$entries[$object->id])) {
            throw new \RarException('Invalid RarEntry');
        }

        return $object;
    }

    /** @return list<ObjectEntry> */
    public static function getEntries(ObjectEntry $archive, Context $ctx): array
    {
        $state = self::$archives[$archive->id];
        $out = [];
        foreach ($state['entries'] as $i => $_) {
            $out[] = self::entryObject($ctx, $archive->id, $i);
        }

        return $out;
    }

    public static function getEntry(ObjectEntry $archive, Context $ctx, string $name): ?ObjectEntry
    {
        $state = self::$archives[$archive->id];
        $name = str_replace('\\', '/', $name);
        foreach ($state['entries'] as $i => $row) {
            if ($row['name'] === $name) {
                return self::entryObject($ctx, $archive->id, $i);
            }
        }

        return null;
    }

    public static function isBroken(ObjectEntry $archive): bool
    {
        return self::$archives[$archive->id]['broken'];
    }

    public static function isSolid(ObjectEntry $archive): bool
    {
        return self::$archives[$archive->id]['solid'];
    }

    public static function getComment(ObjectEntry $archive): string
    {
        return self::$archives[$archive->id]['comment'];
    }

    public static function close(ObjectEntry $archive): bool
    {
        if (!isset(self::$archives[$archive->id])) {
            return true;
        }
        self::$archives[$archive->id]['closed'] = true;

        return true;
    }

    /** PECL rar_allow_broken_set / RarArchive::setAllowBroken (#27878). */
    public static function setAllowBroken(ObjectEntry $archive, bool $allowBroken): bool
    {
        self::$archives[$archive->id]['allowBroken'] = $allowBroken;

        return true;
    }

    public static function allowBroken(ObjectEntry $archive): bool
    {
        return self::$archives[$archive->id]['allowBroken'] ?? false;
    }

    public static function archivePath(ObjectEntry $archive): string
    {
        return self::$archives[$archive->id]['path'];
    }

    /** @return array{name: string, data: string, crc: int, packed: int, unpacked: int, hostOs: int, method: int, isDir: bool} */
    public static function entryRow(ObjectEntry $entry): array
    {
        $meta = self::$entries[$entry->id];
        $arch = self::$archives[$meta['archiveId']] ?? null;
        if (null === $arch || $arch['closed']) {
            throw new \RarException('The archive is already closed');
        }

        return $arch['entries'][$meta['index']];
    }

    public static function extractEntry(ObjectEntry $entry, string $dir, ?string $filepath = null): bool
    {
        $row = self::entryRow($entry);
        if ($row['isDir']) {
            $target = null !== $filepath && '' !== $filepath
                ? $filepath
                : rtrim($dir, '/\\').'/'.$row['name'];
            if (!is_dir($target) && !@mkdir($target, 0777, true) && !is_dir($target)) {
                throw new \RarException('Failed to create directory '.$target);
            }

            return true;
        }
        $target = null !== $filepath && '' !== $filepath
            ? $filepath
            : rtrim($dir, '/\\').'/'.$row['name'];
        $parent = dirname($target);
        if ('' !== $parent && '.' !== $parent && !is_dir($parent) && !@mkdir($parent, 0777, true) && !is_dir($parent)) {
            throw new \RarException('Failed to create directory '.$parent);
        }
        $n = VmFsWriteNative::write($target, $row['data']);
        if (false === $n) {
            throw new \RarException('Failed to write '.$target);
        }

        return true;
    }

    public static function coerceStringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmString::coerceStringBuiltinArg($var, $label, $index, $paramName);
    }

    private static function entryObject(Context $ctx, int $archiveId, int $index): ObjectEntry
    {
        $class = $ctx->classes[self::ENTRY_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('RarEntry is not registered');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::$entries[$object->id] = ['archiveId' => $archiveId, 'index' => $index];

        return $object;
    }
}

/** Shared VM wiring for ext/rar class methods (#6237). */
abstract class RarClassMethod extends VmClassMethod
{
}

final class RarArchiveOpen extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('open');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $argOffset = 0;
        if ($argc >= 1) {
            $maybe = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $maybe->type
                && VmRar::ARCHIVE_LC === strtolower($maybe->toObject()->class->name)) {
                $argOffset = 1;
            }
        }
        $userArgc = $argc - $argOffset;
        if ($userArgc < 1) {
            throw new \ArgumentCountError('RarArchive::open() expects at least 1 argument, '.$userArgc.' given');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('RarArchive::open() requires a VM context');
        }
        $filename = VmRar::coerceStringArg($frame->calledArgs[$argOffset], 'RarArchive::open', 0, 'filename');
        $password = null;
        if ($userArgc >= 2) {
            $pwVar = $frame->calledArgs[$argOffset + 1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $pwVar->type) {
                $password = VmRar::coerceStringArg($frame->calledArgs[$argOffset + 1], 'RarArchive::open', 1, 'password');
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        try {
            $frame->returnVar->object(VmRar::open($ctx, $filename, $password));
        } catch (\RarException $e) {
            throw $e;
        }
    }
}

final class RarArchiveGetEntries extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('getEntries');
    }

    public function execute(Frame $frame): void
    {
        $archive = VmRar::requireArchive($frame->calledArgs[0], 'RarArchive::getEntries()');
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('RarArchive::getEntries() requires a VM context');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        foreach (VmRar::getEntries($archive, $ctx) as $entry) {
            $slot = new Variable();
            $slot->object($entry);
            $ht->append($slot);
        }
        $frame->returnVar->array($ht);
    }
}

final class RarArchiveGetEntry extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('getEntry');
    }

    public function execute(Frame $frame): void
    {
        $archive = VmRar::requireArchive($frame->calledArgs[0], 'RarArchive::getEntry()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('RarArchive::getEntry() expects exactly 1 argument, 0 given');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('RarArchive::getEntry() requires a VM context');
        }
        $name = VmRar::coerceStringArg($frame->calledArgs[1], 'RarArchive::getEntry', 1, 'entryname');
        if (null === $frame->returnVar) {
            return;
        }
        $entry = VmRar::getEntry($archive, $ctx, $name);
        if (null === $entry) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($entry);
        }
    }
}

final class RarArchiveIsBroken extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('isBroken');
    }

    public function execute(Frame $frame): void
    {
        $archive = VmRar::requireArchive($frame->calledArgs[0], 'RarArchive::isBroken()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmRar::isBroken($archive));
    }
}

final class RarArchiveIsSolid extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('isSolid');
    }

    public function execute(Frame $frame): void
    {
        $archive = VmRar::requireArchive($frame->calledArgs[0], 'RarArchive::isSolid()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmRar::isSolid($archive));
    }
}

final class RarArchiveGetComment extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('getComment');
    }

    public function execute(Frame $frame): void
    {
        $archive = VmRar::requireArchive($frame->calledArgs[0], 'RarArchive::getComment()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmRar::getComment($archive));
    }
}

final class RarArchiveClose extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $archive = VmRar::requireArchive($frame->calledArgs[0], 'RarArchive::close()');
        $ok = VmRar::close($archive);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

final class RarArchiveToString extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $archive = VmRar::requireArchive($frame->calledArgs[0], 'RarArchive::__toString()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string('RarArchive('.VmRar::archivePath($archive).')');
    }
}

final class RarEntryGetName extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::getName()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmRar::entryRow($entry)['name']);
    }
}

final class RarEntryGetUnpackedSize extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('getUnpackedSize');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::getUnpackedSize()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmRar::entryRow($entry)['unpacked']);
    }
}

final class RarEntryGetPackedSize extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('getPackedSize');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::getPackedSize()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmRar::entryRow($entry)['packed']);
    }
}

final class RarEntryGetCrc extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('getCrc');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::getCrc()');
        if (null === $frame->returnVar) {
            return;
        }
        // pecl-rar returns 8 hex digits lowercase
        $frame->returnVar->string(sprintf('%08x', VmRar::entryRow($entry)['crc'] & 0xffffffff));
    }
}

final class RarEntryGetHostOs extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('getHostOs');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::getHostOs()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmRar::entryRow($entry)['hostOs']);
    }
}

final class RarEntryGetMethod extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('getMethod');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::getMethod()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmRar::entryRow($entry)['method']);
    }
}

final class RarEntryIsDirectory extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('isDirectory');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::isDirectory()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmRar::entryRow($entry)['isDir']);
    }
}

final class RarEntryIsEncrypted extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('isEncrypted');
    }

    public function execute(Frame $frame): void
    {
        VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::isEncrypted()');
        if (null === $frame->returnVar) {
            return;
        }
        // Store engine rejects encrypted archives at open; surviving entries are not encrypted.
        $frame->returnVar->bool(false);
    }
}

final class RarEntryExtract extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('extract');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::extract()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('RarEntry::extract() expects at least 1 argument, 0 given');
        }
        $dir = VmRar::coerceStringArg($frame->calledArgs[1], 'RarEntry::extract', 1, 'dir');
        $filepath = null;
        if (\count($frame->calledArgs) >= 3) {
            $fp = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $fp->type) {
                $filepath = VmRar::coerceStringArg($frame->calledArgs[2], 'RarEntry::extract', 2, 'filepath');
            }
        }
        $ok = VmRar::extractEntry($entry, $dir, $filepath);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

final class RarEntryToString extends RarClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $entry = VmRar::requireEntry($frame->calledArgs[0], 'RarEntry::__toString()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string('RarEntry('.VmRar::entryRow($entry)['name'].')');
    }
}
