<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM libc fread/fwrite body for __compiler_copy (#32466).
 *
 * NestedJIT {@see CopyJitHelper} cannot copy under thin AOT: host \\copy() re-enters
 * __compiler_copy, and FFI is unavailable in the native binary. Platform stdio copy is
 * the justified thin ABI (php-src ext/standard/file.c — php_copy_file).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(copy)
 */
final class CopyLibcRuntime
{
    private const STAT_BUF_SIZE = 144;

    private const STAT_MODE_OFFSET = 24;

    public static function emit(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('copy_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        $from = $fn->getParam(0);
        $to = $fn->getParam(1);
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $nullStr = $strPtr->constNull();
        $nullFile = $i8p->constNull();

        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $from, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $to, $nullStr)
        );
        $fail = $fn->appendBasicBlock('copy_libc_fail');
        $openIn = $fn->appendBasicBlock('copy_libc_open_in');
        $context->builder->branchIf($badArgs, $fail, $openIn);

        $context->builder->positionAtEnd($openIn);
        $src = self::stringData($context, $from);
        $dst = self::stringData($context, $to);
        $in = $context->builder->call($context->lookupFunction('fopen'), $src, self::literalCstr($context, 'rb'));
        $inNull = $context->builder->icmp(Builder::INT_EQ, $in, $nullFile);
        $openOut = $fn->appendBasicBlock('copy_libc_open_out');
        $context->builder->branchIf($inNull, $fail, $openOut);

        $context->builder->positionAtEnd($openOut);
        $out = $context->builder->call($context->lookupFunction('fopen'), $dst, self::literalCstr($context, 'wb'));
        $outNull = $context->builder->icmp(Builder::INT_EQ, $out, $nullFile);
        $closeInFail = $fn->appendBasicBlock('copy_libc_close_in_fail');
        $prep = $fn->appendBasicBlock('copy_libc_prep');
        $context->builder->branchIf($outNull, $closeInFail, $prep);

        $context->builder->positionAtEnd($closeInFail);
        $context->builder->call($context->lookupFunction('fclose'), $in);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($prep);
        $okSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($one, $okSlot);
        $bufSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(8192));
        $buf = self::stackBytesPtr($context, $bufSlot);
        $loop = $fn->appendBasicBlock('copy_libc_loop');
        $write = $fn->appendBasicBlock('copy_libc_write');
        $afterRead = $fn->appendBasicBlock('copy_libc_after_read');
        $writeFail = $fn->appendBasicBlock('copy_libc_write_fail');
        $shortTail = $fn->appendBasicBlock('copy_libc_short_tail');
        $setReadErr = $fn->appendBasicBlock('copy_libc_set_read_err');
        $afterLoop = $fn->appendBasicBlock('copy_libc_after_loop');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $n = $context->builder->call(
            $context->lookupFunction('fread'),
            $buf,
            $sizeT->constInt(1, false),
            $sizeT->constInt(8192, false),
            $in
        );
        $hasBytes = $context->builder->icmp(Builder::INT_UGT, $n, $sizeT->constInt(0, false));
        $context->builder->branchIf($hasBytes, $write, $afterRead);

        $context->builder->positionAtEnd($write);
        $written = $context->builder->call(
            $context->lookupFunction('fwrite'),
            $buf,
            $sizeT->constInt(1, false),
            $n,
            $out
        );
        $writeBad = $context->builder->icmp(Builder::INT_NE, $written, $n);
        $context->builder->branchIf($writeBad, $writeFail, $afterRead);

        $context->builder->positionAtEnd($writeFail);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($afterRead);
        $shortRead = $context->builder->icmp(Builder::INT_ULT, $n, $sizeT->constInt(8192, false));
        $context->builder->branchIf($shortRead, $shortTail, $loop);

        $context->builder->positionAtEnd($shortTail);
        $inErr = $context->builder->call($context->lookupFunction('ferror'), $in);
        $hasErr = $context->builder->icmp(Builder::INT_NE, $inErr, $zero);
        $context->builder->branchIf($hasErr, $setReadErr, $afterLoop);

        $context->builder->positionAtEnd($setReadErr);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($afterLoop);
        $closeOut = $context->builder->call($context->lookupFunction('fclose'), $out);
        $outBad = $context->builder->icmp(Builder::INT_NE, $closeOut, $zero);
        $afterCloseOut = $fn->appendBasicBlock('copy_libc_after_close_out');
        $closeOutErr = $fn->appendBasicBlock('copy_libc_close_out_err');
        $context->builder->branchIf($outBad, $closeOutErr, $afterCloseOut);
        $context->builder->positionAtEnd($closeOutErr);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterCloseOut);

        $context->builder->positionAtEnd($afterCloseOut);
        $closeIn = $context->builder->call($context->lookupFunction('fclose'), $in);
        $inBad = $context->builder->icmp(Builder::INT_NE, $closeIn, $zero);
        $afterCloseIn = $fn->appendBasicBlock('copy_libc_after_close_in');
        $closeInErr = $fn->appendBasicBlock('copy_libc_close_in_err');
        $context->builder->branchIf($inBad, $closeInErr, $afterCloseIn);
        $context->builder->positionAtEnd($closeInErr);
        $context->builder->store($zero, $okSlot);
        $context->builder->branch($afterCloseIn);

        $context->builder->positionAtEnd($afterCloseIn);
        $ok = $context->builder->load($okSlot);
        $okBool = $context->builder->icmp(Builder::INT_EQ, $ok, $one);
        $chmodBlock = $fn->appendBasicBlock('copy_libc_chmod');
        $retBlock = $fn->appendBasicBlock('copy_libc_ret');
        $context->builder->branchIf($okBool, $chmodBlock, $retBlock);

        $context->builder->positionAtEnd($chmodBlock);
        $stSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::STAT_BUF_SIZE));
        $stBase = self::stackBytesPtr($context, $stSlot);
        $stRc = $context->builder->call($context->lookupFunction('stat'), $src, $stBase);
        $stOk = $context->builder->icmp(Builder::INT_EQ, $stRc, $zero);
        $chmodTail = $fn->appendBasicBlock('copy_libc_chmod_tail');
        $chmodDo = $fn->appendBasicBlock('copy_libc_chmod_do');
        $context->builder->branchIf($stOk, $chmodDo, $chmodTail);
        $context->builder->positionAtEnd($chmodDo);
        $mode64 = self::statFieldI32ToI64($context, $stBase, self::STAT_MODE_OFFSET);
        $context->builder->call(
            $context->lookupFunction('chmod'),
            $dst,
            $context->builder->truncOrBitCast($mode64, $i32)
        );
        $context->builder->branch($chmodTail);
        $context->builder->positionAtEnd($chmodTail);
        $context->builder->branch($retBlock);

        $context->builder->positionAtEnd($retBlock);
        $context->builder->returnValue($context->builder->load($okSlot));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zero);
    }

    private static function ensureLibc(Context $context): void
    {
        // ensureExternalDecl (#33774): getNamedFunction first — bare lookup→addFunction
        // catch minted ferror.1 / stat.1 / chmod.1 (#31894 / #32122 / #33550).
        LibcExtern::ensureStdioFile($context);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        foreach ([
            ['ferror', $i32, [$i8p]],
            ['stat', $i32, [$i8p, $i8p]],
            ['chmod', $i32, [$i8p, $i32]],
        ] as [$name, $ret, $params]) {
            LibcExtern::ensureExternalDecl(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
    }

    private static function stackBytesPtr(Context $context, Value $slot): Value
    {
        return $context->builder->pointerCast($slot, $context->getTypeFromString('int8*'));
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $litGlobal = $context->constantStringFromString($text);
        $litPtr = $context->builder->load($litGlobal);
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($litPtr, $map['value']);
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function statFieldI32ToI64(Context $context, Value $statBase, int $offset): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $at = $context->builder->gep($statBase, $i64->constInt($offset, false));
        $v = $context->builder->load($context->builder->pointerCast($at, $i32->pointerType(0)));

        return $context->builder->zExt($v, $i64);
    }
}
