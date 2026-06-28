<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamPath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCfg\Func as CfgFunc;

/**
 * SplFileObject — file stream wrapper (php-src ext/spl/spl_directory.c; #12520).
 */
final class SplFileObjectBuiltin
{
    public const CLASS_LC = 'splfileobject';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        SplFileInfoBuiltin::registerClass($ctx);

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplFileObject');
        $entry->parentLc = SplFileInfoBuiltin::CLASS_LC;
        if (isset($ctx->classes['stringable'])
            && !\in_array('Stringable', $entry->interfaces, true)) {
            $entry->interfaces[] = 'Stringable';
        }
        foreach (['RecursiveIterator', 'Traversable', 'Iterator', 'SeekableIterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new SplFileObjectConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'fgets' => SplFileObjectFgets::class,
            'fwrite' => SplFileObjectFwrite::class,
            'rewind' => SplFileObjectRewind::class,
            'next' => SplFileObjectNext::class,
            'valid' => SplFileObjectValid::class,
            'key' => SplFileObjectKey::class,
            'current' => SplFileObjectCurrent::class,
            'eof' => SplFileObjectEof::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['fgets'],
            $entry->methods['fwrite'],
            $entry->methods['rewind'],
            $entry->methods['valid'],
            $entry->methods['current'],
            $entry->methods['eof']
        );
    }
}

final class SplFileObjectConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::__construct() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $pathname = VmStreamPath::coerceNonEmptyPathArg($frame->calledArgs[1], 'SplFileObject::__construct');
        $mode = 'r';
        if (isset($frame->calledArgs[2])) {
            $mode = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'SplFileObject::__construct',
                1,
                'mode'
            );
        }
        if (isset($frame->calledArgs[3])) {
            $useIncludePath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[3],
                'SplFileObject::__construct',
                2,
                'use_include_path'
            );
            if ($useIncludePath) {
                $resolved = VmFs::resolveIncludePath($pathname);
                if (false !== $resolved) {
                    $pathname = $resolved;
                }
            }
        }
        $handle = VmFs::fopen($pathname, $mode, $frame->vmContext);
        if (false === $handle) {
            throw new \RuntimeException(
                'SplFileObject::__construct('.$pathname.'): Failed to open stream: No such file or directory'
            );
        }
        SplFileInfoStorage::init($object, $pathname);
        SplFileObjectStorage::setHandle($object, $handle);
    }
}

final class SplFileObjectFgets extends VmClassMethod
{
    private const LENGTH_ERROR = 'SplFileObject::fgets(): Argument #1 ($length) must be greater than 0';

    public function __construct()
    {
        parent::__construct('fgets');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fgets()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $length = null;
        if (isset($frame->calledArgs[1])) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'SplFileObject::fgets',
                1,
                'length'
            );
            if ($length <= 0) {
                throw new \ValueError(self::LENGTH_ERROR);
            }
        }
        $line = SplFileObjectStorage::fgets($object, $length);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
    }
}

final class SplFileObjectRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::rewind()'
        );
        SplFileObjectStorage::rewind($object);
    }
}

final class SplFileObjectNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::next()'
        );
        SplFileObjectStorage::next($object);
    }
}

final class SplFileObjectValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::valid()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool($frame, SplFileObjectStorage::valid($object));
    }
}

final class SplFileObjectKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplFileObjectStorage::key($object));
    }
}

final class SplFileObjectCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::current()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $line = SplFileObjectStorage::current($object);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
    }
}

final class SplFileObjectEof extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('eof');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::eof()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        SplIteratorSupport::setReturnBool($frame, SplFileObjectStorage::eof($object));
    }
}

final class SplFileObjectFwrite extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fwrite');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFileObjectBuiltin::CLASS_LC,
            'SplFileObject::fwrite()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFileObject::fwrite() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'SplFileObject::fwrite',
            0,
            'data'
        );
        $length = null;
        if (isset($frame->calledArgs[2])) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'SplFileObject::fwrite',
                1,
                'length'
            );
        }
        $written = VmFs::fwrite(SplFileObjectStorage::handle($object), $data, $length);
        if (false === $written) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }
}
