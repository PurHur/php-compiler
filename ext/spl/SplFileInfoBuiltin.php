<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

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
            '__tostring' => SplFileInfoToString::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['getpath'], $entry->methods['getfilename'], $entry->methods['getpathname']);
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
        $object = SplIteratorSupport::receiver(
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
        $object = SplIteratorSupport::receiver(
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
        $object = SplIteratorSupport::receiver(
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
        $object = SplIteratorSupport::receiver(
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
        $object = SplIteratorSupport::receiver(
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
        $object = SplIteratorSupport::receiver(
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
