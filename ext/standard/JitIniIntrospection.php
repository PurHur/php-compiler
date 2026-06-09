<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for php_ini_loaded_file() / php_ini_scanned_files() (#6117). */
final class JitIniIntrospection
{
    private const ENV_LOADED_FILE = 'PHP_COMPILER_INI_LOADED_FILE';

    private const ENV_SCANNED_FILES = 'PHP_COMPILER_INI_SCANNED_FILES';

    private static int $blockSerial = 0;

    public static function loadedFile(Context $context): Value
    {
        return self::envStringOrFalse($context, self::ENV_LOADED_FILE);
    }

    public static function scannedFiles(Context $context): Value
    {
        return self::envStringOrFalse($context, self::ENV_SCANNED_FILES);
    }

    private static function envStringOrFalse(Context $context, string $envName): Value
    {
        $id = (string) (++self::$blockSerial);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zeroI64 = $i64->constInt(0, false);

        $nameCstr = $context->builder->pointerCast($context->constantFromString($envName), $i8p);
        $envVal = $context->builder->call($context->lookupFunction('getenv'), $nameCstr);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $envVal, $i8p->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $checkEmptyBlock = BasicBlockHelper::append($context, 'ini_intro_empty_'.$id);
        $writeStringBlock = BasicBlockHelper::append($context, 'ini_intro_str_'.$id);
        $writeFalseBlock = BasicBlockHelper::append($context, 'ini_intro_false_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'ini_intro_done_'.$id);

        $context->builder->branchIf($isNull, $writeFalseBlock, $checkEmptyBlock);

        $context->builder->positionAtEnd($checkEmptyBlock);
        $envLen = $context->builder->call($context->lookupFunction('strlen'), $envVal);
        $envLenI64 = $envLen->typeOf() === $i64
            ? $envLen
            : $context->builder->zExt($envLen, $i64);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $envLenI64, $zeroI64);
        $context->builder->branchIf($isEmpty, $writeFalseBlock, $writeStringBlock);

        $context->builder->positionAtEnd($writeFalseBlock);
        JitValueBox::writeBool($context, $slot, $i32->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($writeStringBlock);
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $envLenI64,
            $envVal
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $resultStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
