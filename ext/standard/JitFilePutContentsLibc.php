<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM lowering for {@see phpc_file_put_contents_kernel} — thin libc fopen/fwrite (#19966).
 *
 * Used inside {@see FilePutContentsJitHelper} so nested helper TUs do not recurse through
 * file_put_contents() under user-script AOT (#16075; same shape as {@see JitRenameKernel}).
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_stream_ex
 */
final class JitFilePutContentsLibc
{
    private const FILE_APPEND = 8;

    /** Emit libc write path; builder must be positioned at the bridge entry block. */
    public static function emitBody(Context $context, LlvmFunction $fn): void
    {
        $path = $fn->getParam(0);
        $data = $fn->getParam(1);
        $flags = $fn->getParam(2);
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
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
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $dataLen, $i64->constInt(0, false));
        $emptyBlock = $fn->appendBasicBlock('fpc_libc_empty_data');
        $writeBlock = $fn->appendBasicBlock('fpc_libc_write_data');
        $context->builder->branchIf($zeroLen, $emptyBlock, $writeBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->call($context->lookupFunction('fclose'), $stream);
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->positionAtEnd($writeBlock);
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
