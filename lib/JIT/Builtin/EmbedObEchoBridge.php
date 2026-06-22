<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * MCJIT embed echo write(2) bridges — called from {@see EmbedObOutput} (#9956).
 *
 * Int/double formatting uses libc snprintf (parity with {@see EmbedObJitHelper} PHP SSOT).
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
        self::implementSnprintfEcho($context, '__phpc_ob_echo_ll', '%lld');
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
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $sizeT->constInt($bufSize, false),
            $context->builder->pointerCast($context->constantFromString($fmt), $i8p),
            $fn->getParam(0)
        );
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
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
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
