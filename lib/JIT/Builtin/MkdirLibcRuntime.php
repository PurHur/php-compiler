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
 * LLVM libc mkdir(2) body for __phpc_jit_mkdir (#33402).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\MkdirJitHelper} cannot create dirs under thin
 * AOT: host \\mkdir() re-enters __phpc_jit_mkdir (peer touch #28995 / chown #32466). Platform
 * mkdir(2) is the justified thin ABI (php-src VCWD_MKDIR / php_mkdir).
 *
 * Recursive: walk path components, ignore EEXIST on parents; final component must succeed
 * (EEXIST → false, matching Zend "File exists").
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(mkdir) / php_mkdir
 */
final class MkdirLibcRuntime
{
    /** Linux glibc EEXIST */
    private const EEXIST = 17;

    public static function emit(Context $context, LlvmFunction $fn): void
    {
        self::ensureLibc($context);

        $entry = $fn->appendBasicBlock('mkdir_libc_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');

        $path = $fn->getParam(0);
        $modeI64 = $fn->getParam(1);
        $recursive = $fn->getParam(2);
        $zero = $i32->constInt(0, false);
        $true = $i1->constInt(1, false);
        $false = $i1->constInt(0, false);
        $zeroSize = $sizeT->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);

        $fail = $fn->appendBasicBlock('mkdir_libc_fail');
        $checkPath = $fn->appendBasicBlock('mkdir_libc_check_path');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $path, $strPtr->constNull());
        $context->builder->branchIf($isNull, $fail, $checkPath);

        $context->builder->positionAtEnd($checkPath);
        $p = self::stringData($context, $path);
        $mode = $context->builder->trunc(
            $context->builder->and($modeI64, $i64->constInt(0777, false)),
            $i32
        );

        $isRec = $context->builder->icmp(Builder::INT_NE, $recursive, $false);
        $recBb = $fn->appendBasicBlock('mkdir_libc_recursive');
        $plainBb = $fn->appendBasicBlock('mkdir_libc_plain');
        $context->builder->branchIf($isRec, $recBb, $plainBb);

        // Non-recursive: single mkdir(2); EEXIST → false.
        $context->builder->positionAtEnd($plainBb);
        $rcPlain = $context->builder->call($context->lookupFunction('mkdir'), $p, $mode);
        $plainOk = $context->builder->icmp(Builder::INT_EQ, $rcPlain, $zero);
        $context->builder->returnValue($context->builder->select($plainOk, $true, $false));

        // Recursive: strdup path, walk '/', mkdir each prefix (ignore EEXIST), then final.
        $context->builder->positionAtEnd($recBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $p);
        $lenZero = $context->builder->icmp(Builder::INT_EQ, $len, $zeroSize);
        $recFailEmpty = $fn->appendBasicBlock('mkdir_libc_rec_empty');
        $recCopy = $fn->appendBasicBlock('mkdir_libc_rec_copy');
        $context->builder->branchIf($lenZero, $recFailEmpty, $recCopy);

        $context->builder->positionAtEnd($recFailEmpty);
        $context->builder->returnValue($false);

        $context->builder->positionAtEnd($recCopy);
        $lenPlus = $context->builder->add($len, $oneSize);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $lenPlus);
        $bufNull = $context->builder->icmp(Builder::INT_EQ, $buf, $i8p->constNull());
        $recFailMalloc = $fn->appendBasicBlock('mkdir_libc_rec_malloc_fail');
        $recInit = $fn->appendBasicBlock('mkdir_libc_rec_init');
        $context->builder->branchIf($bufNull, $recFailMalloc, $recInit);

        $context->builder->positionAtEnd($recFailMalloc);
        $context->builder->returnValue($false);

        $context->builder->positionAtEnd($recInit);
        $context->builder->call($context->lookupFunction('memcpy'), $buf, $p, $lenPlus);
        // i = 1 (skip leading slash so we do not mkdir(""))
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($oneSize, $iSlot);
        $loop = $fn->appendBasicBlock('mkdir_libc_rec_loop');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $i = $context->builder->load($iSlot);
        $pastEnd = $context->builder->icmp(Builder::INT_UGE, $i, $len);
        $afterWalk = $fn->appendBasicBlock('mkdir_libc_rec_after_walk');
        $loopBody = $fn->appendBasicBlock('mkdir_libc_rec_body');
        $context->builder->branchIf($pastEnd, $afterWalk, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $chPtr = $context->builder->gep($buf, $i);
        $ch = $context->builder->load($chPtr);
        $isSlash = $context->builder->icmp(
            Builder::INT_EQ,
            $ch,
            $i8->constInt(\ord('/'), false)
        );
        $doPrefix = $fn->appendBasicBlock('mkdir_libc_rec_prefix');
        $nextI = $fn->appendBasicBlock('mkdir_libc_rec_next');
        $context->builder->branchIf($isSlash, $doPrefix, $nextI);

        $context->builder->positionAtEnd($doPrefix);
        $context->builder->store($i8->constInt(0, false), $chPtr);
        $rcPre = $context->builder->call($context->lookupFunction('mkdir'), $buf, $mode);
        $preOk = $context->builder->icmp(Builder::INT_EQ, $rcPre, $zero);
        $errnoPtr = $context->builder->call($context->lookupFunction('__errno_location'));
        $errnoPre = $context->builder->load($errnoPtr);
        $context->builder->store($ch, $chPtr); // restore '/'
        $preExist = $context->builder->icmp(
            Builder::INT_EQ,
            $errnoPre,
            $i32->constInt(self::EEXIST, false)
        );
        $preAccept = $context->builder->or($preOk, $preExist);
        $preFail = $fn->appendBasicBlock('mkdir_libc_rec_prefix_fail');
        $context->builder->branchIf($preAccept, $nextI, $preFail);

        $context->builder->positionAtEnd($preFail);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($false);

        $context->builder->positionAtEnd($nextI);
        $context->builder->store($context->builder->add($i, $oneSize), $iSlot);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($afterWalk);
        $rcFinal = $context->builder->call($context->lookupFunction('mkdir'), $buf, $mode);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $finalOk = $context->builder->icmp(Builder::INT_EQ, $rcFinal, $zero);
        $context->builder->returnValue($context->builder->select($finalOk, $true, $false));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($false);
    }

    private static function ensureLibc(Context $context): void
    {
        // Canonical decls (#33774): getNamedFunction first — bare lookup→addFunction
        // catch minted strlen.1 / memcpy.1 / mkdir.1 (#31894 / #32122 / #33550).
        LibcExtern::ensureMallocFamily($context);
        LibcExtern::ensureStrlenDecl($context);
        LibcExtern::ensureMemcpyDecl($context);
        LibcExtern::ensureErrnoLocationDecl($context);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        LibcExtern::ensureExternalDecl(
            $context,
            'mkdir',
            $context->context->functionType($i32, false, $i8p, $i32)
        );
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }
}
