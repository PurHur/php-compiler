<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCfg\Func as CfgFunc;

/**
 * SplTempFileObject — in-memory temp stream (php-src ext/spl/spl_temp_file_object.c; #12891).
 */
final class SplTempFileObjectBuiltin
{
    public const CLASS_LC = 'spltempfileobject';

    /** php-src SPL_TEMP_FILE_DEFAULT max memory before spill (2 MiB). */
    public const DEFAULT_MAX_MEMORY = 2_097_152;

    public static function registerClass(Context $ctx): void
    {
        SplFileObjectBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplTempFileObject');
        $entry->parentLc = SplFileObjectBuiltin::CLASS_LC;
        // Zend rematerializes a different flattened table than SplFileObject (#25799).
        // php-src ext/spl/spl_directory.c — SplTempFileObject class entry.
        $entry->interfaces = [];
        foreach (['seekableiterator', 'iterator', 'traversable', 'recursiveiterator', 'stringable'] as $ifaceLc) {
            if (isset($ctx->classes[$ifaceLc])) {
                $entry->interfaces[] = $ifaceLc;
            }
        }

        $entry->constructor = new SplTempFileObjectConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['__construct']);
    }
}

final class SplTempFileObjectConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            SplTempFileObjectBuiltin::CLASS_LC,
            'SplTempFileObject::__construct()'
        );
        $maxMemory = SplTempFileObjectBuiltin::DEFAULT_MAX_MEMORY;
        if (isset($frame->calledArgs[1])) {
            // php-src Z_PARAM_LONG — soft-null cites parameter #1 ($maxMemory) (#31807).
            $maxMemory = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'SplTempFileObject::__construct',
                1,
                'maxMemory'
            );
            if ($maxMemory < 0) {
                throw new \ValueError(
                    'SplTempFileObject::__construct(): Argument #1 ($maxMemory) must be greater than or equal to 0'
                );
            }
        }
        unset($maxMemory);

        $handle = VmPhpMemoryStream::open('php://temp', 'w+b');
        if (false === $handle) {
            throw new \RuntimeException('SplTempFileObject::__construct(): Failed to open temp stream');
        }
        SplFileInfoStorage::init($object, 'php://temp');
        SplFileObjectStorage::setHandle($object, $handle, 'w+b');
    }
}
