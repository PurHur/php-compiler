<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\standard\VmStatPath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
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
            'getfileinfo' => SplFileInfoGetFileInfo::class,
            'getpathinfo' => SplFileInfoGetPathInfo::class,
            'openfile' => SplFileInfoOpenFile::class,
            'setfileclass' => SplFileInfoSetFileClass::class,
            'setinfoclass' => SplFileInfoSetInfoClass::class,
            '__debuginfo' => SplFileInfoDebugInfo::class,
            '_bad_state_ex' => SplFileInfoBadStateEx::class,
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
        $entry->methodNames['getfileinfo'] = 'getFileInfo';
        $entry->methodNames['getpathinfo'] = 'getPathInfo';
        $entry->methodNames['openfile'] = 'openFile';
        $entry->methodNames['setfileclass'] = 'setFileClass';
        $entry->methodNames['setinfoclass'] = 'setInfoClass';
        $entry->methodNames['__debuginfo'] = '__debugInfo';
        $entry->methodNames['_bad_state_ex'] = '_bad_state_ex';

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
            $entry->methods['getsize'],
            $entry->methods['getfileinfo'],
            $entry->methods['getpathinfo'],
            $entry->methods['openfile'],
            $entry->methods['setfileclass'],
            $entry->methods['setinfoclass'],
            $entry->methods['__debuginfo'],
            $entry->methods['_bad_state_ex']
        );
    }

    /**
     * Private pathName/fileName (+ SplFileObject openMode/delimiter/enclosure)
     * for var_dump (php-src spl_filesystem_object_get_debug_info; #20108).
     */
    public static function debugInfoTable(ObjectEntry $object): HashTable
    {
        $ht = new HashTable();
        $pathName = new Variable();
        $pathName->string(SplFileInfoStorage::pathname($object));
        $ht->addNew("\0SplFileInfo\0pathName", $pathName);

        $fileName = new Variable();
        // php-src debug fileName matches getFilename(), not php basename() (#24338).
        $fileName->string(SplFileInfoStorage::filename($object));
        $ht->addNew("\0SplFileInfo\0fileName", $fileName);

        if (SplFileObjectStorage::hasHandle($object)) {
            $openMode = new Variable();
            $openMode->string(SplFileObjectStorage::openMode($object));
            $ht->addNew("\0SplFileObject\0openMode", $openMode);

            [$separator, $enclosure] = SplFileObjectStorage::getCsvControl($object);
            $delim = new Variable();
            $delim->string($separator);
            $ht->addNew("\0SplFileObject\0delimiter", $delim);
            $encl = new Variable();
            $encl->string($enclosure);
            $ht->addNew("\0SplFileObject\0enclosure", $encl);
        }

        return $ht;
    }

    /**
     * Resolve optional class-string for setInfoClass/setFileClass/getFileInfo/getPathInfo
     * (php-src `|C` / `|C!` in spl_directory.c).
     */
    public static function resolveFactoryClass(
        Frame $frame,
        ?Variable $arg,
        string $method,
        string $paramName,
        string $baseClassLc,
        string $baseClassName,
        bool $allowNull,
        string $defaultClassLc
    ): ClassEntry {
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException($method.'() requires VM context');
        }
        if (null === $arg) {
            $entry = $ctx->classes[$defaultClassLc] ?? null;
            if (null === $entry) {
                throw new \LogicException($baseClassName.' is not registered in this compiler build');
            }

            return $entry;
        }
        $resolved = $arg->resolveIndirect();
        if ($allowNull && Variable::TYPE_NULL === $resolved->type) {
            $entry = $ctx->classes[$defaultClassLc] ?? null;
            if (null === $entry) {
                throw new \LogicException($baseClassName.' is not registered in this compiler build');
            }

            return $entry;
        }
        $className = VmString::coerceStringBuiltinArg($arg, $method, 0, $paramName);
        $entry = self::lookupClassEntry($ctx, $className);
        if (null === $entry || !InterfaceCheck::entryIsInstanceOf($entry, $baseClassLc, $ctx)) {
            $suffix = $allowNull ? ' or null' : '';
            throw new \TypeError(
                $method.'(): Argument #1 ($'.$paramName.') must be a class name derived from '
                .$baseClassName.$suffix.', '.$className.' given'
            );
        }

        return $entry;
    }

    public static function lookupClassEntry(Context $ctx, string $className): ?ClassEntry
    {
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null !== $entry) {
            return $entry;
        }
        $ctx->autoloadClass($className);

        return VmReflection::resolveClassEntry($ctx, $className);
    }

    /** php-src spl_filesystem_object_create_type(SPL_FS_INFO) / create_info. */
    public static function createInfoObject(Frame $frame, ClassEntry $ce, string $pathname): ObjectEntry
    {
        $ctx = $frame->vmContext;
        if (null === $ctx || null === $ctx->runtime) {
            throw new \LogicException('SplFileInfo factory requires VM runtime');
        }
        $declaringLc = self::constructorDeclaringClassLc($ce, $ctx);
        if (null === $declaringLc || self::CLASS_LC === $declaringLc) {
            $obj = new ObjectEntry($ce);
            $obj->constructed = true;
            SplFileInfoStorage::init($obj, $pathname);

            return $obj;
        }
        $pathArg = new Variable();
        $pathArg->string($pathname);

        return $ctx->runtime->vm->instantiateFromNewCallable($ce, $frame, $pathArg);
    }

    /** php-src spl_filesystem_object_create_type(SPL_FS_FILE). */
    public static function createFileObject(
        Frame $frame,
        ClassEntry $ce,
        string $pathname,
        string $mode,
        bool $useIncludePath
    ): ObjectEntry {
        $ctx = $frame->vmContext;
        if (null === $ctx || null === $ctx->runtime) {
            throw new \LogicException('SplFileInfo::openFile() requires VM runtime');
        }
        SplFileObjectBuiltin::registerClass($ctx);
        if ($useIncludePath) {
            $resolved = VmFs::resolveIncludePath($pathname);
            if (false !== $resolved) {
                $pathname = $resolved;
            }
        }
        $declaringLc = self::constructorDeclaringClassLc($ce, $ctx);
        if (null === $declaringLc || SplFileObjectBuiltin::CLASS_LC === $declaringLc) {
            $handle = VmFs::fopen($pathname, $mode, $ctx);
            if (false === $handle) {
                throw new \RuntimeException(
                    'SplFileObject::__construct('.$pathname.'): Failed to open stream: No such file or directory'
                );
            }
            $obj = new ObjectEntry($ce);
            $obj->constructed = true;
            SplFileInfoStorage::init($obj, $pathname);
            SplFileObjectStorage::setHandle($obj, $handle, $mode);

            return $obj;
        }
        $pathArg = new Variable();
        $pathArg->string($pathname);
        $modeArg = new Variable();
        $modeArg->string($mode);

        return $ctx->runtime->vm->instantiateFromNewCallable($ce, $frame, $pathArg, $modeArg);
    }

    private static function constructorDeclaringClassLc(ClassEntry $ce, Context $ctx): ?string
    {
        $current = $ce;
        while (true) {
            $ctor = $current->constructor ?? $current->methods['__construct'] ?? null;
            if (null !== $ctor) {
                // Inherited builtin SplFileInfo/SplFileObject ctors must use the C fast path
                // (php-src: constructor scope == spl_ce_SplFileInfo / SplFileObject).
                if ($ctor instanceof SplFileInfoConstruct) {
                    return SplFileInfoBuiltin::CLASS_LC;
                }
                if ($ctor instanceof SplFileObjectConstruct) {
                    return SplFileObjectBuiltin::CLASS_LC;
                }

                return strtolower(ltrim($current->name, '\\'));
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return null;
            }
            $current = $ctx->classes[$current->parentLc];
        }
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
            'filename'
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
        // php-src intern->path — empty when the only slash is leading (#24338).
        $frame->returnVar->string(SplFileInfoStorage::path($object));
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
        // ACE uses called class (DirectoryIterator inherits) (#30837).
        $this->requireExactUserArgCount($frame, $object->class->name.'::getFilename', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(SplFileInfoStorage::filename($object));
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
        // ACE uses called class (#30837; ZEND_PARSE_PARAMETERS_NONE).
        $this->requireExactUserArgCount($frame, $object->class->name.'::getPathname', 0);
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
        // ACE uses called class (#30837; ZEND_PARSE_PARAMETERS_NONE).
        $this->requireExactUserArgCount($frame, $object->class->name.'::getSize', 0);
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

/** php-src SplFileInfo::getFileInfo — spl_filesystem_object_create_type(SPL_FS_INFO). */
final class SplFileInfoGetFileInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFileInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getFileInfo()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $ce = SplFileInfoBuiltin::resolveFactoryClass(
            $frame,
            $frame->calledArgs[1] ?? null,
            'SplFileInfo::getFileInfo',
            'class',
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo',
            true,
            SplFileInfoStorage::infoClassLc($object)
        );
        $info = SplFileInfoBuiltin::createInfoObject(
            $frame,
            $ce,
            SplFileInfoStorage::pathname($object)
        );
        $frame->returnVar->object($info);
    }
}

/** php-src SplFileInfo::getPathInfo — dirname pathname as SplFileInfo. */
final class SplFileInfoGetPathInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPathInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::getPathInfo()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $pathname = SplFileInfoStorage::pathname($object);
        if ('' === $pathname) {
            $frame->returnVar->null();

            return;
        }
        $ce = SplFileInfoBuiltin::resolveFactoryClass(
            $frame,
            $frame->calledArgs[1] ?? null,
            'SplFileInfo::getPathInfo',
            'class',
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo',
            true,
            SplFileInfoStorage::infoClassLc($object)
        );
        $info = SplFileInfoBuiltin::createInfoObject(
            $frame,
            $ce,
            VmString::dirname($pathname)
        );
        $frame->returnVar->object($info);
    }
}

/** php-src SplFileInfo::openFile — spl_filesystem_object_create_type(SPL_FS_FILE). */
final class SplFileInfoOpenFile extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('openFile');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::openFile()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('SplFileInfo::openFile() requires VM context');
        }
        $mode = 'r';
        if (isset($frame->calledArgs[1])) {
            $mode = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'SplFileInfo::openFile',
                0,
                'mode'
            );
        }
        $useIncludePath = false;
        if (isset($frame->calledArgs[2])) {
            $useIncludePath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[2],
                'SplFileInfo::openFile',
                1,
                'useIncludePath'
            );
        }
        // Arg #3 ($context) accepted for signature parity; SplFileObject fopen path ignores it today.
        SplFileObjectBuiltin::registerClass($frame->vmContext);
        $ce = $frame->vmContext->classes[SplFileInfoStorage::fileClassLc($object)] ?? null;
        if (null === $ce) {
            throw new \LogicException('SplFileObject is not registered in this compiler build');
        }
        $file = SplFileInfoBuiltin::createFileObject(
            $frame,
            $ce,
            SplFileInfoStorage::pathname($object),
            $mode,
            $useIncludePath
        );
        $frame->returnVar->object($file);
    }
}

/** php-src SplFileInfo::setFileClass — class used by openFile(). */
final class SplFileInfoSetFileClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setFileClass');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::setFileClass()'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('SplFileInfo::setFileClass() requires VM context');
        }
        SplFileObjectBuiltin::registerClass($frame->vmContext);
        $ce = SplFileInfoBuiltin::resolveFactoryClass(
            $frame,
            $frame->calledArgs[1] ?? null,
            'SplFileInfo::setFileClass',
            'class',
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject',
            false,
            SplFileObjectBuiltin::CLASS_LC
        );
        SplFileInfoStorage::setFileClassLc($object, strtolower(ltrim($ce->name, '\\')));
    }
}

/** php-src SplFileInfo::setInfoClass — class used by getFileInfo()/getPathInfo(). */
final class SplFileInfoSetInfoClass extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setInfoClass');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::setInfoClass()'
        );
        $ce = SplFileInfoBuiltin::resolveFactoryClass(
            $frame,
            $frame->calledArgs[1] ?? null,
            'SplFileInfo::setInfoClass',
            'class',
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo',
            false,
            SplFileInfoBuiltin::CLASS_LC
        );
        SplFileInfoStorage::setInfoClassLc($object, strtolower(ltrim($ce->name, '\\')));
    }
}

/** php-src SplFileInfo::__debugInfo — spl_filesystem_object_get_debug_info (#20108). */
final class SplFileInfoDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::__debugInfo()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplFileInfoBuiltin::debugInfoTable($object));
    }
}

/** php-src SplFileInfo::_bad_state_ex — invalid parent-constructor state (#20109). */
final class SplFileInfoBadStateEx extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('_bad_state_ex');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            SplFileInfoBuiltin::CLASS_LC,
            'SplFileInfo::_bad_state_ex()'
        );
        throw new \Error('The parent constructor was not called: the object is in an invalid state');
    }
}
