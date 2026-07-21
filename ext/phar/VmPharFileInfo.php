<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\spl\SplFileInfoBuiltin;
use PHPCompiler\ext\spl\SplFileInfoStorage;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;

/**
 * PharFileInfo — SplFileInfo subclass for phar entries (php-src ext/phar/phar_object.c; #19892).
 */
final class VmPharFileInfo
{
    public const CLASS_LC = 'pharfileinfo';

    /** Regular-file type bit (stat st_mode S_IFREG) — Zend PharFileInfo::getPerms (#21652). */
    private const S_IFREG = 0100000;

    /** Default entry mode matching Zend fresh PharFileInfo (100644). */
    private const DEFAULT_PERMS = self::S_IFREG | 0644;

    /** @var array<int, array{content: string, name: string, archive: string, crcChecked: bool, compressed: bool, hasMetadata: bool, metadata: mixed, perms: int, flags: int}> */
    private static array $store = [];

    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])
            && isset($ctx->classes[self::CLASS_LC]->methods['iscrcchecked'])
            && isset($ctx->classes[self::CLASS_LC]->methods['hasmetadata'])
            && isset($ctx->classes[self::CLASS_LC]->methods['chmod'])
            && isset($ctx->classes[self::CLASS_LC]->methods['getpharflags'])) {
            return;
        }

        SplFileInfoBuiltin::registerClass($ctx);

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = $ctx->classes[self::CLASS_LC] ?? new ClassEntry('PharFileInfo');
        $entry->isInternal = true;
        $entry->parentLc = SplFileInfoBuiltin::CLASS_LC;

        $entry->constructor = new PharFileInfoConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        foreach ([
            'getcontent' => PharFileInfoGetContent::class,
            'iscrcchecked' => PharFileInfoIsCRCChecked::class,
            'getcrc32' => PharFileInfoGetCRC32::class,
            'getcompressedsize' => PharFileInfoGetCompressedSize::class,
            'iscompressed' => PharFileInfoIsCompressed::class,
            'hasmetadata' => PharFileInfoHasMetadata::class,
            'getmetadata' => PharFileInfoGetMetadata::class,
            'setmetadata' => PharFileInfoSetMetadata::class,
            'delmetadata' => PharFileInfoDelMetadata::class,
            'getperms' => PharFileInfoGetPerms::class,
            'chmod' => PharFileInfoChmod::class,
            'getpharflags' => PharFileInfoGetPharFlags::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getcontent'] = 'getContent';
        $entry->methodNames['iscrcchecked'] = 'isCRCChecked';
        $entry->methodNames['getcrc32'] = 'getCRC32';
        $entry->methodNames['getcompressedsize'] = 'getCompressedSize';
        $entry->methodNames['iscompressed'] = 'isCompressed';
        $entry->methodNames['hasmetadata'] = 'hasMetadata';
        $entry->methodNames['getmetadata'] = 'getMetadata';
        $entry->methodNames['setmetadata'] = 'setMetadata';
        $entry->methodNames['delmetadata'] = 'delMetadata';
        $entry->methodNames['getperms'] = 'getPerms';
        $entry->methodNames['chmod'] = 'chmod';
        $entry->methodNames['getpharflags'] = 'getPharFlags';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function createFromEntry(
        Context $ctx,
        string $archivePath,
        string $localname,
        string $content
    ): ObjectEntry {
        self::register($ctx);
        $info = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $info->constructed = true;
        $localname = \ltrim(\str_replace('\\', '/', $localname), '/');
        $archivePath = \str_replace('\\', '/', $archivePath);
        $pathname = 'phar://'.$archivePath.'/'.$localname;
        SplFileInfoStorage::init($info, $pathname);
        self::$store[$info->id] = [
            'content' => $content,
            'name' => $localname,
            'archive' => $archivePath,
            'crcChecked' => true,
            'compressed' => false,
            'hasMetadata' => false,
            'metadata' => null,
            'perms' => self::DEFAULT_PERMS,
            'flags' => 0,
        ];

        return $info;
    }

    public static function initDirect(ObjectEntry $object, string $filename): void
    {
        $filename = \str_replace('\\', '/', $filename);
        SplFileInfoStorage::init($object, $filename);
        self::$store[$object->id] = [
            'content' => '',
            'name' => VmString::basename($filename),
            'archive' => '',
            'crcChecked' => false,
            'compressed' => false,
            'hasMetadata' => false,
            'metadata' => null,
            'perms' => self::DEFAULT_PERMS,
            'flags' => 0,
        ];
        $object->constructed = true;
    }

    public static function hydrateMetadata(ObjectEntry $object, mixed $metadata): void
    {
        self::state($object);
        self::$store[$object->id]['hasMetadata'] = true;
        self::$store[$object->id]['metadata'] = $metadata;
    }

    public static function hydrateAttrs(ObjectEntry $object, int $perms, int $flags): void
    {
        self::state($object);
        self::$store[$object->id]['perms'] = $perms;
        self::$store[$object->id]['flags'] = $flags;
    }

    /** @return array{content: string, name: string, archive: string, crcChecked: bool, compressed: bool, hasMetadata: bool, metadata: mixed, perms: int, flags: int} */
    public static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \Error('PharFileInfo object has not been correctly initialized by its constructor');
        }

        return self::$store[$object->id];
    }

    public static function hasMetadata(ObjectEntry $object): bool
    {
        return self::state($object)['hasMetadata'];
    }

    public static function getMetadata(ObjectEntry $object): mixed
    {
        $st = self::state($object);

        return $st['hasMetadata'] ? $st['metadata'] : null;
    }

    public static function setMetadata(ObjectEntry $object, mixed $metadata): void
    {
        $st = self::state($object);
        self::$store[$object->id]['hasMetadata'] = true;
        self::$store[$object->id]['metadata'] = $metadata;
        if ('' !== $st['archive']) {
            VmPharArchive::setEntryMetadata($st['archive'], $st['name'], $metadata);
        }
    }

    public static function delMetadata(ObjectEntry $object): bool
    {
        $st = self::state($object);
        self::$store[$object->id]['hasMetadata'] = false;
        self::$store[$object->id]['metadata'] = null;
        if ('' !== $st['archive']) {
            VmPharArchive::delEntryMetadata($st['archive'], $st['name']);
        }

        return true;
    }

    public static function getPerms(ObjectEntry $object): int
    {
        return self::state($object)['perms'];
    }

    public static function getPharFlags(ObjectEntry $object): int
    {
        return self::state($object)['flags'];
    }

    public static function chmod(ObjectEntry $object, int $perms): void
    {
        $st = self::state($object);
        // php-src keeps permission bits and reports as regular-file mode (100xxx).
        $mode = self::S_IFREG | ($perms & 07777);
        self::$store[$object->id]['perms'] = $mode;
        if ('' !== $st['archive']) {
            VmPharArchive::setEntryAttrs(
                $st['archive'],
                $st['name'],
                $mode,
                self::$store[$object->id]['flags']
            );
        }
    }

    public static function requireReceiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on an object', $label));
        }

        return $var->toObject();
    }
}

/** PharFileInfo::__construct(string $filename) — php-src zim_PharFileInfo___construct (#19892). */
final class PharFileInfoConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::__construct()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('PharFileInfo::__construct() expects exactly 1 argument, 0 given');
        }
        $filename = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'PharFileInfo::__construct',
            0,
            'filename'
        );
        VmPharFileInfo::initDirect($object, $filename);
    }
}

/** PharFileInfo::getContent(): string (#19892). */
final class PharFileInfoGetContent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getContent');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::getContent()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmPharFileInfo::state($object)['content']);
        }
    }
}

/** PharFileInfo::isCRCChecked(): bool (#19892). */
final class PharFileInfoIsCRCChecked extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isCRCChecked');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::isCRCChecked()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharFileInfo::state($object)['crcChecked']);
        }
    }
}

/** PharFileInfo::getCRC32(): int (#19892). */
final class PharFileInfoGetCRC32 extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCRC32');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::getCRC32()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int((int) \crc32(VmPharFileInfo::state($object)['content']));
        }
    }
}

/** PharFileInfo::getCompressedSize(): int (#19892). */
final class PharFileInfoGetCompressedSize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCompressedSize');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::getCompressedSize()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(\strlen(VmPharFileInfo::state($object)['content']));
        }
    }
}

/** PharFileInfo::isCompressed(?int $compression = null): bool (#19892). */
final class PharFileInfoIsCompressed extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isCompressed');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::isCompressed()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharFileInfo::state($object)['compressed']);
        }
    }
}

/** PharFileInfo::hasMetadata() — php-src zim_PharFileInfo_hasMetadata (#21651). */
final class PharFileInfoHasMetadata extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasMetadata');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::hasMetadata()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmPharFileInfo::hasMetadata($object));
        }
    }
}

/** PharFileInfo::getMetadata() — php-src zim_PharFileInfo_getMetadata (#21651). */
final class PharFileInfoGetMetadata extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMetadata');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::getMetadata()');
        $imported = VmJson::import(VmPharFileInfo::getMetadata($object));
        $frame->returnVar->copyFrom($imported);
    }
}

/** PharFileInfo::setMetadata() — php-src zim_PharFileInfo_setMetadata (#21651). */
final class PharFileInfoSetMetadata extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setMetadata');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::setMetadata()');
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'PharFileInfo::setMetadata() expects exactly 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }
        $meta = VmJson::export($frame->calledArgs[1]->resolveIndirect(), $frame->vmContext, null, $frame);
        VmPharFileInfo::setMetadata($object, $meta);
    }
}

/** PharFileInfo::delMetadata() — php-src zim_PharFileInfo_delMetadata (#21651). */
final class PharFileInfoDelMetadata extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('delMetadata');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::delMetadata()');
        $ok = VmPharFileInfo::delMetadata($object);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

/** PharFileInfo::getPerms() — override SplFileInfo for phar:// entries (#21652). */
final class PharFileInfoGetPerms extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPerms');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::getPerms()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmPharFileInfo::getPerms($object));
        }
    }
}

/** PharFileInfo::chmod() — php-src zim_PharFileInfo_chmod (#21652). */
final class PharFileInfoChmod extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('chmod');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::chmod()');
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'PharFileInfo::chmod() expects exactly 1 argument, %d given',
                \max(0, $argc - 1)
            ));
        }
        $perms = $frame->calledArgs[1]->resolveIndirect()->toInt();
        VmPharFileInfo::chmod($object, $perms);
    }
}

/** PharFileInfo::getPharFlags() — php-src zim_PharFileInfo_getPharFlags (#21652). */
final class PharFileInfoGetPharFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPharFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = VmPharFileInfo::requireReceiver($frame, 'PharFileInfo::getPharFlags()');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmPharFileInfo::getPharFlags($object));
        }
    }
}
