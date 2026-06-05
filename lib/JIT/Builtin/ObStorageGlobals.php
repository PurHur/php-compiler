<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ObStackLimits;

/**
 * LLVM globals for JIT/AOT ob_*() buffer stack (issue #5582).
 *
 * VM uses {@see \PHPCompiler\VM\OutputBuffer}; JIT/AOT use these globals via
 * {@see ObOutputRuntime} (issue #5314).
 */
final class ObStorageGlobals
{
    public const GLOBAL_LEVEL = '__phpc_ob_level';

    public const GLOBAL_STORAGE = '__phpc_ob_storage';

    public const GLOBAL_LEN = '__phpc_ob_len';

    public static function ensureGlobals(Context $context): void
    {
        $depth = ObStackLimits::MAX_DEPTH;
        $bufSize = ObStackLimits::BUF_SIZE;
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');

        if (null === $context->module->getNamedGlobal(self::GLOBAL_LEVEL)) {
            $level = $context->module->addGlobal($i32, self::GLOBAL_LEVEL);
            $level->setInitializer($i32->constInt(0, false));
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_STORAGE)) {
            $rowTy = $i8->arrayType($bufSize);
            $storageTy = $rowTy->arrayType($depth);
            $storage = $context->module->addGlobal($storageTy, self::GLOBAL_STORAGE);
            $storage->setInitializer($storageTy->constNull());
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_LEN)) {
            $lenTy = $i64->arrayType($depth);
            $len = $context->module->addGlobal($lenTy, self::GLOBAL_LEN);
            $len->setInitializer($lenTy->constNull());
        }
    }
}
