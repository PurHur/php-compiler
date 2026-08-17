<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\Builtin\SysGetTempDirRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM NestedJIT leaf for tempnam() — thin libc mkstemp (#27089, #29940).
 *
 * Used while NestedJIT compiles {@see TempnamJitHelper} `@tempnam` via
 * {@see \PHPCompiler\JIT\Builtin\StringTempnam} — no always-on thin-AOT ABI fork
 * (peer SysGetTempDirRuntime #29433 / gethostname #29364).
 * php-src: ext/standard/file.c — php_tempnam / php_open_temporary_file
 */
final class JitTempnamKernel
{
    private const PATH_MAX = 4096;

    /** Module-local NestedJIT leaf — distinct from helper-bridge {@code __phpc_jit_tempnam}. */
    private const LEAF_ABI = '__phpc_jit_tempnam_leaf';

    /** @return Value `__string__*` — null when tempnam fails (crypt #29545 NestedJIT shape) */
    public static function invoke(Context $context, Value $directory, Value $prefix): Value
    {
        self::ensureNestedLeafBody($context);

        return $context->builder->call(
            $context->lookupFunction(self::LEAF_ABI),
            $directory,
            $prefix
        );
    }

    public static function ensureNestedLeafBody(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::LEAF_ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::LEAF_ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        TypeErrorRaise::ensureLinked($context);
        StringTriggerError::ensureLinked($context);
        self::ensureLibc($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::LEAF_ABI,
                $context->context->functionType($strPtr, false, $strPtr, $strPtr)
            );

        self::emit($context, $fn);
        $context->registerFunction(self::LEAF_ABI, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');

        foreach ([
            // memchr/mkstemp module-local after LibcExtern always-on drop (#31655).
            ['memchr', $i8p, [$i8p, $i32, $sizeT]],
            // strrchr(3) module-local after LibcExtern/Module always-on drop (#31458).
            ['strrchr', $i8p, [$i8p, $i32]],
            ['strlen', $i64, [$i8p]],
            ['memcpy', $i8p, [$i8p, $i8p, $sizeT]],
            ['snprintf', $i32, [$i8p, $sizeT, $i8p]],
            ['mkstemp', $i32, [$i8p]],
            // close(2) module-local after LibcExtern always-on drop (#31817).
            ['close', $i32, [$i32]],
            ['chmod', $i32, [$i8p, $i32]],
            ['__string__init', $strPtr, [$i64, $i8p]],
        ] as [$name, $ret, $params]) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function emit(Context $context, LlvmFunction $fn): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $strMap = $context->structFieldMap['__string__'];

        $entry = $fn->appendBasicBlock('tempnam_kernel_entry');
        $failBb = $fn->appendBasicBlock('tempnam_kernel_fail');
        $bodyBb = $fn->appendBasicBlock('tempnam_kernel_body');
        $context->builder->positionAtEnd($entry);

        $dirObj = $fn->getParam(0);
        $pfxObj = $fn->getParam(1);
        $nullStr = $strPtr->constNull();
        $bad = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $dirObj, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $pfxObj, $nullStr)
        );
        $context->builder->branchIf($bad, $failBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $dir = self::stringData($context, $dirObj);
        $pfx = self::stringData($context, $pfxObj);
        $dirLen = $context->builder->load($context->builder->structGep($dirObj, $strMap['length']));
        $pfxLen = $context->builder->load($context->builder->structGep($pfxObj, $strMap['length']));

        self::rejectNullByte(
            $context,
            $fn,
            $dir,
            $dirLen,
            'tempnam(): Argument #1 ($directory) must not contain any null bytes'
        );
        self::rejectNullByte(
            $context,
            $fn,
            $pfx,
            $pfxLen,
            'tempnam(): Argument #2 ($prefix) must not contain any null bytes'
        );

        $dirEmpty = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($dir), $i8->constInt(0, false));
        $resolveDirBb = $fn->appendBasicBlock('tempnam_kernel_resolve_dir');
        $useArgDirBb = $fn->appendBasicBlock('tempnam_kernel_use_arg_dir');
        $normBb = $fn->appendBasicBlock('tempnam_kernel_norm');
        $dirSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->branchIf($dirEmpty, $resolveDirBb, $useArgDirBb);

        $context->builder->positionAtEnd($resolveDirBb);
        $sysDir = SysGetTempDirRuntime::invokeNestedLeaf($context);
        $sysNull = $context->builder->icmp(Builder::INT_EQ, $sysDir, $nullStr);
        $sysOkBb = $fn->appendBasicBlock('tempnam_kernel_sys_ok');
        $context->builder->branchIf($sysNull, $failBb, $sysOkBb);
        $context->builder->positionAtEnd($sysOkBb);
        $context->builder->store(self::stringData($context, $sysDir), $dirSlot);
        $context->builder->branch($normBb);

        $context->builder->positionAtEnd($useArgDirBb);
        $context->builder->store($dir, $dirSlot);
        $context->builder->branch($normBb);

        $context->builder->positionAtEnd($normBb);
        $pfxBufSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(64));
        $pfxBuf = $context->builder->pointerCast($pfxBufSlot, $i8p);
        self::copyNormalizedPrefix($context, $fn, $pfx, $pfxBuf);

        $tplSlot = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::PATH_MAX));
        $tpl = $context->builder->pointerCast($tplSlot, $i8p);
        $useDir = $context->builder->load($dirSlot);
        $primaryOk = self::mkstempAttempt($context, $fn, $useDir, $pfxBuf, $tpl, 'tempnam_primary');
        $retPrimary = $fn->appendBasicBlock('tempnam_kernel_ret_primary');
        $fallback = $fn->appendBasicBlock('tempnam_kernel_fallback');
        $context->builder->branchIf($primaryOk, $retPrimary, $fallback);

        $context->builder->positionAtEnd($retPrimary);
        self::returnStringValue($context, $tpl);

        $context->builder->positionAtEnd($fallback);
        // php-src: failed primary → notice + system temp fallback.
        self::emitNotice($context);
        $fbDir = SysGetTempDirRuntime::invokeNestedLeaf($context);
        $fbNull = $context->builder->icmp(Builder::INT_EQ, $fbDir, $nullStr);
        $tryFb = $fn->appendBasicBlock('tempnam_kernel_try_fb');
        $context->builder->branchIf($fbNull, $failBb, $tryFb);
        $context->builder->positionAtEnd($tryFb);
        $fbData = self::stringData($context, $fbDir);
        $fbOk = self::mkstempAttempt($context, $fn, $fbData, $pfxBuf, $tpl, 'tempnam_fb');
        $retFb = $fn->appendBasicBlock('tempnam_kernel_ret_fb');
        $context->builder->branchIf($fbOk, $retFb, $failBb);
        $context->builder->positionAtEnd($retFb);
        self::returnStringValue($context, $tpl);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());
    }

    private static function returnStringValue(Context $context, Value $cstr): void
    {
        $pathStr = self::cstrToString($context, $cstr);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $pathStr);
        $context->builder->returnValue($owned);
    }

    private static function copyNormalizedPrefix(
        Context $context,
        LlvmFunction $fn,
        Value $pfx,
        Value $pfxBuf
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        $startSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $lastSepSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($i8p->constNull(), $lastSepSlot);

        $slash = $context->builder->call($context->lookupFunction('strrchr'), $pfx, $i32->constInt(ord('/'), false));
        $bslash = $context->builder->call($context->lookupFunction('strrchr'), $pfx, $i32->constInt(ord('\\'), false));
        $slashNull = $context->builder->icmp(Builder::INT_EQ, $slash, $i8p->constNull());
        $bslashNull = $context->builder->icmp(Builder::INT_EQ, $bslash, $i8p->constNull());

        $afterSlash = $fn->appendBasicBlock('tempnam_pfx_after_slash');
        $slashSet = $fn->appendBasicBlock('tempnam_pfx_slash_set');
        $context->builder->branchIf($slashNull, $afterSlash, $slashSet);
        $context->builder->positionAtEnd($slashSet);
        $context->builder->store($slash, $lastSepSlot);
        $context->builder->branch($afterSlash);
        $context->builder->positionAtEnd($afterSlash);

        $afterBslash = $fn->appendBasicBlock('tempnam_pfx_after_bslash');
        $bslashCheck = $fn->appendBasicBlock('tempnam_pfx_bslash_check');
        $bslashSet = $fn->appendBasicBlock('tempnam_pfx_bslash_set');
        $context->builder->branchIf($bslashNull, $afterBslash, $bslashCheck);
        $context->builder->positionAtEnd($bslashCheck);
        $lastSep = $context->builder->load($lastSepSlot);
        $lastSepNull = $context->builder->icmp(Builder::INT_EQ, $lastSep, $i8p->constNull());
        $bslashGt = $context->builder->icmp(Builder::INT_UGT, $bslash, $lastSep);
        $context->builder->branchIf(
            $context->builder->or($lastSepNull, $bslashGt),
            $bslashSet,
            $afterBslash
        );
        $context->builder->positionAtEnd($bslashSet);
        $context->builder->store($bslash, $lastSepSlot);
        $context->builder->branch($afterBslash);
        $context->builder->positionAtEnd($afterBslash);

        $copyBlock = $fn->appendBasicBlock('tempnam_pfx_copy');
        $usePfxStart = $fn->appendBasicBlock('tempnam_pfx_use_start');
        $useLastStart = $fn->appendBasicBlock('tempnam_pfx_use_last');
        $lastSep = $context->builder->load($lastSepSlot);
        $lastSepNull = $context->builder->icmp(Builder::INT_EQ, $lastSep, $i8p->constNull());
        $context->builder->branchIf($lastSepNull, $usePfxStart, $useLastStart);
        $context->builder->positionAtEnd($usePfxStart);
        $context->builder->store($pfx, $startSlot);
        $context->builder->branch($copyBlock);
        $context->builder->positionAtEnd($useLastStart);
        $context->builder->store($context->builder->gep($lastSep, $i64->constInt(1, false)), $startSlot);
        $context->builder->branch($copyBlock);

        $context->builder->positionAtEnd($copyBlock);
        $start = $context->builder->load($startSlot);
        $baseLen = $context->builder->call($context->lookupFunction('strlen'), $start);
        $maxCopy = $sizeT->constInt(63, false);
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $baseLen, $maxCopy),
            $context->builder->intCast($baseLen, $sizeT),
            $maxCopy
        );
        $context->builder->call($context->lookupFunction('memcpy'), $pfxBuf, $start, $copyLen);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->gep($pfxBuf, $context->builder->intCast($copyLen, $i64))
        );
    }

    private static function mkstempAttempt(
        Context $context,
        LlvmFunction $fn,
        Value $dir,
        Value $pfxBuf,
        Value $tpl,
        string $tag
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');

        $format = $fn->appendBasicBlock($tag.'_format');
        $mkstempBb = $fn->appendBasicBlock($tag.'_mkstemp');
        $closeBb = $fn->appendBasicBlock($tag.'_close');
        $failBb = $fn->appendBasicBlock($tag.'_fail');
        $doneBb = $fn->appendBasicBlock($tag.'_done');

        $context->builder->branch($format);
        $context->builder->positionAtEnd($format);
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $tpl,
            $sizeT->constInt(self::PATH_MAX, false),
            self::literalCstr($context, '%s/%sXXXXXX'),
            $dir,
            $pfxBuf
        );
        $tooLong = $context->builder->icmp(Builder::INT_SGE, $n, $i32->constInt(self::PATH_MAX, false));
        $context->builder->branchIf($tooLong, $failBb, $mkstempBb);

        $context->builder->positionAtEnd($mkstempBb);
        $fd = $context->builder->call($context->lookupFunction('mkstemp'), $tpl);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $i32->constInt(0, true));
        $context->builder->branchIf($fdBad, $failBb, $closeBb);

        $context->builder->positionAtEnd($closeBb);
        $context->builder->call($context->lookupFunction('close'), $fd);
        // php-src php_open_temporary_file — mode 0600.
        $context->builder->call(
            $context->lookupFunction('chmod'),
            $tpl,
            $i32->constInt(0600, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $okPhi = $context->builder->phi($i1, $tag.'_ok');
        $okPhi->addIncoming($i1->constInt(0, false), $failBb);
        $okPhi->addIncoming($i1->constInt(1, false), $closeBb);

        return $okPhi;
    }

    private static function rejectNullByte(
        Context $context,
        LlvmFunction $fn,
        Value $data,
        Value $len,
        string $message
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $found = $context->builder->call(
            $context->lookupFunction('memchr'),
            $data,
            $i32->constInt(0, false),
            $context->builder->intCast($len, $sizeT)
        );
        $hasNull = $context->builder->icmp(Builder::INT_NE, $found, $i8p->constNull());
        static $rejectSeq = 0;
        $tag = 'tempnam_nul_'.(string) (++$rejectSeq);
        $ok = $fn->appendBasicBlock($tag.'_ok');
        $bad = $fn->appendBasicBlock($tag.'_bad');
        $context->builder->branchIf($hasNull, $bad, $ok);
        $context->builder->positionAtEnd($bad);
        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($ok);
    }

    private static function emitNotice(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $message = VmFsTempnam::NOTICE_MESSAGE;
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msgPtr);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(8, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function stringData(Context $context, Value $strObj): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($strObj, $map['value']);
    }

    private static function cstrToString(Context $context, Value $cstr): Value
    {
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $litGlobal = $context->constantStringFromString($text);
        $litPtr = $context->builder->load($litGlobal);
        $map = $context->structFieldMap['__string__'];

        return $context->builder->structGep($litPtr, $map['value']);
    }
}
