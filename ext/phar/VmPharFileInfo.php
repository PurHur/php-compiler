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
use PHPCompiler\ext\standard\VmString;

/**
 * PharFileInfo — SplFileInfo subclass for phar entries (php-src ext/phar/phar_object.c; #19892).
 */
final class VmPharFileInfo
{
    public const CLASS_LC = 'pharfileinfo';

    /** @var array<int, array{content: string, name: string, archive: string, crc: int, crcChecked: bool, compressed: bool}> */
    private static array $store = [];

    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])
            && isset($ctx->classes[self::CLASS_LC]->methods['iscrcchecked'])) {
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
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getcontent'] = 'getContent';
        $entry->methodNames['iscrcchecked'] = 'isCRCChecked';
        $entry->methodNames['getcrc32'] = 'getCRC32';
        $entry->methodNames['getcompressedsize'] = 'getCompressedSize';
        $entry->methodNames['iscompressed'] = 'isCompressed';

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
            // PharData .tar: getCRC32() is the ustar header checksum (php-src tar.c).
            'crc' => VmPharTar::entryHeaderChecksum($localname, $content),
            'crcChecked' => true,
            'compressed' => false,
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
            'crc' => 0,
            'crcChecked' => false,
            'compressed' => false,
        ];
        $object->constructed = true;
    }

    /** @return array{content: string, name: string, archive: string, crc: int, crcChecked: bool, compressed: bool} */
    public static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \Error('PharFileInfo object has not been correctly initialized by its constructor');
        }

        return self::$store[$object->id];
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
            $frame->returnVar->int(VmPharFileInfo::state($object)['crc']);
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
