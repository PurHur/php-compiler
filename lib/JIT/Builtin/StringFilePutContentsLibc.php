<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;

/**
 * Thin libc fopen/fwrite bridge for user-script standalone AOT (#16734).
 *
 * Nested FilePutContentsJitHelper does not run in minimal standalone init.
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_stream_ex
 */
final class StringFilePutContentsLibc
{
    private const ABI = '__compiler_file_put_contents';

    private const FILE_APPEND = 8;

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction(self::ABI);
        $entry = $fn->appendBasicBlock('fpc_libc_entry');
        $context->builder->positionAtEnd($entry);

        $path = $fn->getParam(0);
        $data = $fn->getParam(1);
        $flags = $fn->getParam(2);
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $oneI64 = $i64->constInt(1, false);
        $oneSizeT = $sizeT->constInt(1, false);
        $minusOne = $i64->constInt(-1, false);
        $fileAppend = $i64->constInt(self::FILE_APPEND, false);
        $nullPtr = $i8p->constNull();

        $pathLen = $context->builder->load(
            $context->builder->structGep($path, $strMap['length'])
        );
        $pathBytes = $context->builder->structGep($path, $strMap['value']);
        $bufLen = $context->builder->add($pathLen, $oneI64);
        $pathBuf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufLen);
        $pathCStr = $context->builder->pointerCast($pathBuf, $i8p);
        $context->intrinsic->memcpy($pathCStr, $pathBytes, $pathLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($pathCStr, $pathLen)
        );

        $modeW = self::modeCString($context, 'w');
        $modeA = self::modeCString($context, 'a');
        $isAppend = $context->builder->icmp(Builder::INT_EQ, $flags, $fileAppend);
        $mode = $context->builder->select($isAppend, $modeA, $modeW);

        $stream = $context->builder->call(
            $context->lookupFunction('fopen'),
            $pathCStr,
            $mode
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $pathBuf);

        $openFail = $context->builder->icmp(Builder::INT_EQ, $stream, $nullPtr);
        $failBlock = $fn->appendBasicBlock('fpc_libc_open_fail');
        $okBlock = $fn->appendBasicBlock('fpc_libc_open_ok');
        $context->builder->branchIf($openFail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($okBlock);
        $dataLen = $context->builder->load(
            $context->builder->structGep($data, $strMap['length'])
        );
        $dataPtr = $context->builder->pointerCast(
            $context->builder->structGep($data, $strMap['value']),
            $i8p
        );
        $dataSizeT = $context->builder->truncOrBitCast($dataLen, $sizeT);
        $nWritten = $context->builder->call(
            $context->lookupFunction('fwrite'),
            $dataPtr,
            $oneSizeT,
            $dataSizeT,
            $stream
        );
        $context->builder->call($context->lookupFunction('fclose'), $stream);

        $writeFail = $context->builder->icmp(Builder::INT_NE, $nWritten, $dataSizeT);
        $writeFailBlock = BasicBlockHelper::append($context, 'fpc_libc_write_fail');
        $writeOkBlock = BasicBlockHelper::append($context, 'fpc_libc_write_ok');
        $context->builder->branchIf($writeFail, $writeFailBlock, $writeOkBlock);

        $context->builder->positionAtEnd($writeFailBlock);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($writeOkBlock);
        $context->builder->returnValue($context->builder->truncOrBitCast($nWritten, $i64));

        $context->registerFunction(self::ABI, $fn);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function modeCString(Context $context, string $mode): \PHPLLVM\Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $len = strlen($mode) + 1;
        $buf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $context->getTypeFromString('int64')->constInt($len, false)
        );
        $ptr = $context->builder->pointerCast($buf, $i8p);
        for ($i = 0; $i < strlen($mode); ++$i) {
            $context->builder->store(
                $i8->constInt(ord($mode[$i]), false),
                $context->builder->inBoundsGEP($ptr, $context->getTypeFromString('int64')->constInt($i, false))
            );
        }
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($ptr, $context->getTypeFromString('int64')->constInt(strlen($mode), false))
        );

        return $ptr;
    }
}
