<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * MCJIT embed echo bridges — php_write (ob-aware) + IR int format (#9956, #21124).
 *
 * Double formatting still uses host snprintf via {@see __phpc_host_snprintf}
 * (parity with {@see EmbedObJitHelper} PHP SSOT). Int echo avoids snprintf so
 * LLVM 9 MCJIT does not call through a null libc reloc.
 */
final class EmbedObEchoBridge
{
    private static int $blockSuffix = 0;

    public static function implementAll(Context $context): void
    {
        self::$blockSuffix = 0;
        self::implementCstr($context);
        self::implementChar($context);
        self::implementSubstr($context);
        self::implementItoaEchoLl($context);
        self::implementSnprintfEcho($context, '__phpc_ob_echo_double', '%.14g', true);
    }

    public static function implementCstr(Context $context): void
    {
        $fn = self::freshFn($context, '__phpc_ob_echo_cstr');
        if (null === $fn) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $entry = $fn->appendBasicBlock('eoc_entry');
        $done = $fn->appendBasicBlock('eoc_done');
        $work = $fn->appendBasicBlock('eoc_work');
        $context->builder->positionAtEnd($entry);
        $s = $fn->getParam(0);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $s, $i8p->constNull()),
            $done,
            $work
        );
        $context->builder->positionAtEnd($work);
        self::emitWrite($context, $s, $context->builder->call($context->lookupFunction('strlen'), $s));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function implementChar(Context $context): void
    {
        $fn = self::freshFn($context, '__phpc_ob_echo_char');
        if (null === $fn) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $entry = $fn->appendBasicBlock('eoch_entry');
        $context->builder->positionAtEnd($entry);
        $slot = $context->builder->alloca($i8, 1, 'c');
        $context->builder->store($fn->getParam(0), $slot);
        self::emitWrite(
            $context,
            $context->builder->pointerCast($slot, $i8p),
            $context->getTypeFromString('size_t')->constInt(1, false)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function implementSubstr(Context $context): void
    {
        $fn = self::freshFn($context, '__phpc_ob_echo_substr');
        if (null === $fn) {
            return;
        }
        $i8p = $context->getTypeFromString('int8*');
        $entry = $fn->appendBasicBlock('eos_entry');
        $done = $fn->appendBasicBlock('eos_done');
        $work = $fn->appendBasicBlock('eos_work');
        $context->builder->positionAtEnd($entry);
        $s = $fn->getParam(0);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $s, $i8p->constNull()),
            $done,
            $work
        );
        $context->builder->positionAtEnd($work);
        self::emitWrite($context, $s, $fn->getParam(1));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    /**
     * IR decimal format for i64 — avoids MCJIT-null snprintf (#21124).
     * Matches {@see \PHPCompiler\ext\standard\EmbedObJitHelper::formatInt64}.
     */
    public static function implementItoaEchoLl(Context $context): void
    {
        $fn = self::freshFn($context, '__phpc_ob_echo_ll');
        if (null === $fn) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $b = $context->builder;
        $suf = ++self::$blockSuffix;

        $entry = $fn->appendBasicBlock('itoa_entry_'.$suf);
        $zeroBb = $fn->appendBasicBlock('itoa_zero_'.$suf);
        $checkMin = $fn->appendBasicBlock('itoa_checkmin_'.$suf);
        $minBb = $fn->appendBasicBlock('itoa_min_'.$suf);
        $negCheck = $fn->appendBasicBlock('itoa_negcheck_'.$suf);
        $negBb = $fn->appendBasicBlock('itoa_neg_'.$suf);
        $digitsSetup = $fn->appendBasicBlock('itoa_digits_setup_'.$suf);
        $digitCond = $fn->appendBasicBlock('itoa_digit_cond_'.$suf);
        $digitBody = $fn->appendBasicBlock('itoa_digit_body_'.$suf);
        $signBb = $fn->appendBasicBlock('itoa_sign_'.$suf);
        $writeMinus = $fn->appendBasicBlock('itoa_write_minus_'.$suf);
        $emitBb = $fn->appendBasicBlock('itoa_emit_'.$suf);
        $done = $fn->appendBasicBlock('itoa_done_'.$suf);

        $b->positionAtEnd($entry);
        $buf = $b->alloca($i8->arrayType(24), 1, 'itoabuf');
        $bufBase = $b->pointerCast($buf, $i8p);
        $posSlot = $b->alloca($i64, 1, 'pos');
        $uSlot = $b->alloca($i64, 1, 'u');
        $negSlot = $b->alloca($i64, 1, 'neg');
        $b->store($i64->constInt(23, false), $posSlot);
        $b->store($i64->constInt(0, false), $negSlot);
        $val = $fn->getParam(0);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $val, $i64->constInt(0, false)),
            $zeroBb,
            $checkMin
        );

        $b->positionAtEnd($zeroBb);
        // Place '0' at the last slot (index 23), same as the digit loop.
        $zeroPtr = $b->gep($bufBase, $i64->constInt(23, false));
        $b->store($i8->constInt(\ord('0'), false), $zeroPtr);
        $b->store($i64->constInt(22, false), $posSlot);
        $b->branch($emitBb);

        $b->positionAtEnd($checkMin);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $val, $i64->constInt(\PHP_INT_MIN, true)),
            $minBb,
            $negCheck
        );

        $b->positionAtEnd($minBb);
        $minStr = $b->pointerCast($context->constantFromString('-9223372036854775808'), $i8p);
        self::emitWrite($context, $minStr, $sizeT->constInt(20, false));
        $b->branch($done);

        $b->positionAtEnd($negCheck);
        $b->branchIf(
            $b->icmp(Builder::INT_SLT, $val, $i64->constInt(0, false)),
            $negBb,
            $digitsSetup
        );

        $b->positionAtEnd($negBb);
        $b->store($i64->constInt(1, false), $negSlot);
        $b->store($b->negate($val), $uSlot);
        $b->branch($digitCond);

        $b->positionAtEnd($digitsSetup);
        $b->store($val, $uSlot);
        $b->branch($digitCond);

        $b->positionAtEnd($digitCond);
        $u = $b->load($uSlot);
        $b->branchIf(
            $b->icmp(Builder::INT_NE, $u, $i64->constInt(0, false)),
            $digitBody,
            $signBb
        );

        $b->positionAtEnd($digitBody);
        $ten = $i64->constInt(10, false);
        $digit = $b->truncOrBitCast($b->signedRem($u, $ten), $i8);
        $ascii = $b->add($digit, $i8->constInt(\ord('0'), false));
        $pos = $b->load($posSlot);
        $b->store($ascii, $b->gep($bufBase, $pos));
        $b->store($b->sub($pos, $i64->constInt(1, false)), $posSlot);
        $b->store($b->signedDiv($u, $ten), $uSlot);
        $b->branch($digitCond);

        $b->positionAtEnd($signBb);
        $b->branchIf(
            $b->icmp(Builder::INT_NE, $b->load($negSlot), $i64->constInt(0, false)),
            $writeMinus,
            $emitBb
        );

        $b->positionAtEnd($writeMinus);
        $pos = $b->load($posSlot);
        $b->store($i8->constInt(\ord('-'), false), $b->gep($bufBase, $pos));
        $b->store($b->sub($pos, $i64->constInt(1, false)), $posSlot);
        $b->branch($emitBb);

        $b->positionAtEnd($emitBb);
        // Digits occupy (pos+1) .. 23 inclusive → length = 23 - pos
        $pos = $b->load($posSlot);
        $start = $b->gep($bufBase, $b->add($pos, $i64->constInt(1, false)));
        $len = $b->sub($i64->constInt(23, false), $pos);
        self::emitWrite($context, $start, $b->zExt($len, $sizeT));
        $b->branch($done);

        $b->positionAtEnd($done);
        $b->returnVoid();
        $b->clearInsertionPosition();
    }

    public static function implementSnprintfEcho(
        Context $context,
        string $abi,
        string $fmt,
        bool $isDouble = false
    ): void {
        $fn = self::freshFn($context, $abi);
        if (null === $fn) {
            return;
        }
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $bufSize = $isDouble ? 64 : 32;
        $entry = $fn->appendBasicBlock('eose_entry');
        $emit = $fn->appendBasicBlock('eose_emit');
        $done = $fn->appendBasicBlock('eose_done');
        $context->builder->positionAtEnd($entry);
        $buf = $context->builder->alloca($i8->arrayType($bufSize), 1, 'fmtbuf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $fmtPtr = $context->builder->pointerCast($context->constantFromString($fmt), $i8p);
        if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_EMBED === $context->loadType) {
            $n = \PHPCompiler\JIT\McjitEmbedHostEcho::emitHostSnprintf(
                $context,
                $bufPtr,
                $sizeT->constInt($bufSize, false),
                $fmtPtr,
                $fn->getParam(0)
            );
        } else {
            $n = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufPtr,
                $sizeT->constInt($bufSize, false),
                $fmtPtr,
                $fn->getParam(0)
            );
        }
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $n, $i32->constInt(0, false)),
            $emit,
            $done
        );
        $context->builder->positionAtEnd($emit);
        self::emitWrite($context, $bufPtr, $context->builder->zExt($n, $sizeT));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function emitWrite(Context $context, Value $buf, Value $len): void
    {
        if (\PHPCompiler\JIT\Builtin::LOAD_TYPE_EMBED === $context->loadType) {
            \PHPCompiler\JIT\McjitEmbedHostEcho::emitPhpWrite($context, $buf, $len);

            return;
        }
        // Module-local write(2) after LibcExtern always-on drop (#31817).
        \PHPCompiler\JIT\LibcExtern::ensurePosixFd($context);
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            $context->lookupFunction('write'),
            $i32->constInt(1, false),
            $context->builder->pointerCast($buf, $i8p),
            $context->builder->zExt($len, $i64)
        );
    }

    private static function freshFn(Context $context, string $name): ?LlvmFunction
    {
        $fn = $context->module->getNamedFunction($name);
        if (null === $fn || $fn->countBasicBlocks() > 0) {
            return null;
        }

        return $fn;
    }
}
