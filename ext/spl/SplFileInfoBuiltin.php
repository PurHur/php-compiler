<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStatPath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCfg\Func as CfgFunc;

/**
 * SplFileInfo — pathname introspection (php-src ext/spl/spl_directory.c; #12521).
 */
final class SplFileInfoBuiltin
{
    public const CLASS_LC = 'splfileinfo';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplFileInfo');
        if (isset($ctx->classes['stringable'])
            && !\in_array('Stringable', $entry->interfaces, true)) {
            $entry->interfaces[] = 'Stringable';
        }

        $entry->constructor = new SplFileInfoConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'getpath' => SplFileInfoGetPath::class,
            'getfilename' => SplFileInfoGetFilename::class,
            'getbasename' => SplFileInfoGetBasename::class,
            'getpathname' => SplFileInfoGetPathname::class,
            'getextension' => SplFileInfoGetExtension::class,
            'getrealpath' => SplFileInfoGetRealPath::class,
            'gettype' => SplFileInfoGetType::class,
            'isfile' => SplFileInfoIsFile::class,
            'isdir' => SplFileInfoIsDir::class,
            'islink' => SplFileInfoIsLink::class,
            'isreadable' => SplFileInfoIsReadable::class,
            'iswritable' => SplFileInfoIsWritable::class,
            'isexecutable' => SplFileInfoIsExecutable::class,
            'getctime' => SplFileInfoGetCTime::class,
            'getmtime' => SplFileInfoGetMTime::class,
            'getatime' => SplFileInfoGetATime::class,
            'getsize' => SplFileInfoGetSize::class,
            'getperms' => SplFileInfoGetPerms::class,
            'getowner' => SplFileInfoGetOwner::class,
            'getgroup' => SplFileInfoGetGroup::class,
            'getinode' => SplFileInfoGetInode::class,
            'getlinktarget' => SplFileInfoGetLinkTarget::class,
            '__tostring' => SplFileInfoToString::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getextension'] = 'getExtension';
        $entry->methodNames['getrealpath'] = 'getRealPath';
        $entry->methodNames['gettype'] = 'getType';
        $entry->methodNames['isfile'] = 'isFile';
        $entry->methodNames['isdir'] = 'isDir';
        $entry->methodNames['islink'] = 'isLink';
        $entry->methodNames['isreadable'] = 'isReadable';
        $entry->methodNames['iswritable'] = 'isWritable';
        $entry->methodNames['isexecutable'] = 'isExecutable';
        $entry->methodNames['getctime'] = 'getCTime';
        $entry->methodNames['getmtime'] = 'getMTime';
        $entry->methodNames['getatime'] = 'getATime';
        $entry->methodNames['getsize'] = 'getSize';
        $entry->methodNames['getperms'] = 'getPerms';
        $entry->methodNames['getowner'] = 'getOwner';
        $entry->methodNames['getgroup'] = 'getGroup';
        $entry->methodNames['getinode'] = 'getInode';
        $entry->methodNames['getlinktarget'] = 'getLinkTarget';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['getpath'],
            $entry->methods['getfilename'],
            $entry->methods['getpathname'],
            $entry->methods['getextension'],
            $entry->methods['gettype'],
            $entry->methods['isfile'],
            $entry->methods['islink'],
            $entry->methods['getperms'],
            $entry->methods['getsize']
        );
    }
}

final class SplFileInfoConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFileInfo::__construct() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $pathname = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'SplFileInfo::__construct',
            0,
            'file_name'
        );
        SplFileInfoStorage::init($object, $pathname);
    }
}

final class SplFileInfoGetPath extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPath');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getPath()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::dirname(SplFileInfoStorage::pathname($object)));
    }
}

final class SplFileInfoGetFilename extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFilename');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getFilename()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::basename(SplFileInfoStorage::pathname($object)));
    }
}

final class SplFileInfoGetBasename extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getBasename');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getBasename()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $suffix = '';
        if (isset($frame->calledArgs[1])) {
            $suffix = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'SplFileInfo::getBasename',
                0,
                'suffix'
            );
        }
        $frame->returnVar->string(
            VmString::basename(SplFileInfoStorage::pathname($object), $suffix)
        );
    }
}

final class SplFileInfoGetPathname extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPathname');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getPathname()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(SplFileInfoStorage::pathname($object));
    }
}

final class SplFileInfoToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::__toString()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(SplFileInfoStorage::pathname($object));
    }
}

final class SplFileInfoGetExtension extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getExtension');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getExtension()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $extension = VmString::pathinfo($pathname, 4);
        $frame->returnVar->string(\is_string($extension) ? $extension : '');
    }
}

final class SplFileInfoGetRealPath extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getRealPath');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getRealPath()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $resolved = VmString::realpath(SplFileInfoStorage::pathname($object));
        if (false === $resolved) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($resolved);
    }
}

final class SplFileInfoGetType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getType');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getType()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $type = VmFs::fileType($pathname);
        if (false === $type) {
            throw new \RuntimeException('SplFileInfo::getType(): Lstat failed for '.$pathname);
        }
        $frame->returnVar->string($type);
    }
}

final class SplFileInfoIsFile extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isFile');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::isFile()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool(
            $frame,
            VmStatPath::isFile(SplFileInfoStorage::pathname($object))
        );
    }
}

final class SplFileInfoIsLink extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isLink');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::isLink()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool(
            $frame,
            VmStatPath::isLink(SplFileInfoStorage::pathname($object))
        );
    }
}

final class SplFileInfoIsExecutable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isExecutable');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::isExecutable()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool(
            $frame,
            VmStatPath::isExecutable(SplFileInfoStorage::pathname($object))
        );
    }
}

final class SplFileInfoIsDir extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isDir');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::isDir()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool(
            $frame,
            VmStatPath::isDir(SplFileInfoStorage::pathname($object))
        );
    }
}

final class SplFileInfoIsReadable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isReadable');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::isReadable()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool(
            $frame,
            VmStatPath::isReadable(SplFileInfoStorage::pathname($object))
        );
    }
}

final class SplFileInfoIsWritable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isWritable');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::isWritable()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool(
            $frame,
            VmStatPath::isWritable(SplFileInfoStorage::pathname($object))
        );
    }
}

final class SplFileInfoGetCTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCTime');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getCTime()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $ctime = VmFs::fileCtime($pathname);
        if (false === $ctime) {
            throw new \RuntimeException('SplFileInfo::getCTime(): stat failed for '.$pathname);
        }
        $frame->returnVar->int($ctime);
    }
}

final class SplFileInfoGetMTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMTime');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getMTime()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $mtime = VmFs::fileMtime($pathname);
        if (false === $mtime) {
            throw new \RuntimeException('SplFileInfo::getMTime(): stat failed for '.$pathname);
        }
        $frame->returnVar->int($mtime);
    }
}

final class SplFileInfoGetSize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getSize()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $size = VmFs::fileSize($pathname);
        if (false === $size) {
            throw new \RuntimeException('SplFileInfo::getSize(): stat failed for '.$pathname);
        }
        $frame->returnVar->int($size);
    }
}

final class SplFileInfoGetATime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getATime');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getATime()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $atime = VmFs::fileAtime($pathname);
        if (false === $atime) {
            throw new \RuntimeException('SplFileInfo::getATime(): stat failed for '.$pathname);
        }
        $frame->returnVar->int($atime);
    }
}

final class SplFileInfoGetPerms extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPerms');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getPerms()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $perms = VmFs::filePerms($pathname);
        if (false === $perms) {
            throw new \RuntimeException('SplFileInfo::getPerms(): stat failed for '.$pathname);
        }
        $frame->returnVar->int($perms);
    }
}

final class SplFileInfoGetOwner extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getOwner');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getOwner()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $owner = VmFs::fileOwner($pathname);
        if (false === $owner) {
            throw new \RuntimeException('SplFileInfo::getOwner(): stat failed for '.$pathname);
        }
        $frame->returnVar->int($owner);
    }
}

final class SplFileInfoGetGroup extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getGroup');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getGroup()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $group = VmFs::fileGroup($pathname);
        if (false === $group) {
            throw new \RuntimeException('SplFileInfo::getGroup(): stat failed for '.$pathname);
        }
        $frame->returnVar->int($group);
    }
}

final class SplFileInfoGetInode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInode');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getInode()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $inode = VmFs::fileInode($pathname);
        if (false === $inode) {
            throw new \RuntimeException('SplFileInfo::getInode(): stat failed for '.$pathname);
        }
        $frame->returnVar->int($inode);
    }
}

final class SplFileInfoGetLinkTarget extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLinkTarget');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getLinkTarget()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        $target = VmFs::readlink($pathname);
        if (false !== $target) {
            $frame->returnVar->string($target);

            return;
        }
        // php-src spl_directory.c — strerror(errno) after php_sys_readlink failure
        $errnoMsg = false === VmFs::fileType($pathname)
            ? 'No such file or directory'
            : 'Invalid argument';
        throw new \RuntimeException(
            'Unable to read link '.$pathname.', error: '.$errnoMsg
        );
    }
}
