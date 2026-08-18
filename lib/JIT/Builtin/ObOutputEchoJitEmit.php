<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * ob_* echo LLVM bridges routing through {@see ObOutputJitBridge} append path (#9268, #13822).
 */
final class ObOutputEchoJitEmit
{
    private static int $echoBlockSuffix = 0;

    public static function implementAll(Context $context): void
    {
        self::$echoBlockSuffix = 0;
        self::implementObEchoCstr($context);
        self::implementObEchoChar($context);
        self::implementObEchoSubstr($context);
        self::implementObEchoLl($context);
        self::implementObEchoDouble($context);
    }

    /** Forward-declare echo ABI so nested ObOutputJitHelper compile can lower `echo` (#12999). */
    public static function ensureEchoAbiDeclared(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $doubleTy = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');

        self::echoFn($context, '__phpc_ob_echo_cstr', $voidTy, false, $i8p);
        self::echoFn($context, '__phpc_ob_echo_char', $voidTy, false, $i8);
        self::echoFn($context, '__phpc_ob_echo_substr', $voidTy, false, $i8p, $sizeT);
        self::echoFn($context, '__phpc_ob_echo_ll', $voidTy, false, $i64);
        self::echoFn($context, '__phpc_ob_echo_double', $voidTy, false, $doubleTy);
    }

    private static function implementObEchoCstr(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $fn = self::echoFn($context, '__phpc_ob_echo_cstr', $voidTy, false, $i8p);
        $entry = $fn->appendBasicBlock('oec_entry');
        $done = $fn->appendBasicBlock('oec_done');
        $context->builder->positionAtEnd($entry);
        $s = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $s, $i8p->constNull());
        $work = $fn->appendBasicBlock('oec_work');
        $context->builder->branchIf($isNull, $done, $work);
        $context->builder->positionAtEnd($work);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $len = $context->builder->call($context->lookupFunction('strlen'), $s);
        $context->builder->call($context->lookupFunction('__phpc_ob_append_bytes'), $s, $len);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObEchoChar(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = self::echoFn($context, '__phpc_ob_echo_char', $voidTy, false, $i8);
        $entry = $fn->appendBasicBlock('oech_entry');
        $context->builder->positionAtEnd($entry);
        $slot = $context->builder->alloca($i8, 1, 'c');
        $context->builder->store($fn->getParam(0), $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_append_bytes'),
            $context->builder->pointerCast($slot, $context->getTypeFromString('int8*')),
            $sizeT->constInt(1, false)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObEchoSubstr(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = self::echoFn($context, '__phpc_ob_echo_substr', $voidTy, false, $i8p, $sizeT);
        $entry = $fn->appendBasicBlock('oes_entry');
        $done = $fn->appendBasicBlock('oes_done');
        $context->builder->positionAtEnd($entry);
        $s = $fn->getParam(0);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $s, $i8p->constNull());
        $work = $fn->appendBasicBlock('oes_work');
        $context->builder->branchIf($isNull, $done, $work);
        $context->builder->positionAtEnd($work);
        $context->builder->call($context->lookupFunction('__phpc_ob_append_bytes'), $s, $fn->getParam(1));
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObEchoLl(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $fn = self::echoFn($context, '__phpc_ob_echo_ll', $voidTy, false, $i64);
        $entry = $fn->appendBasicBlock('oell_entry');
        $context->builder->positionAtEnd($entry);
        self::emitSnprintfAppend($context, $fn, '%lld', $fn->getParam(0), 32);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementObEchoDouble(Context $context): void
    {
        $voidTy = $context->getTypeFromString('void');
        $doubleTy = $context->getTypeFromString('double');
        $fn = self::echoFn($context, '__phpc_ob_echo_double', $voidTy, false, $doubleTy);
        $entry = $fn->appendBasicBlock('oed_entry');
        $context->builder->positionAtEnd($entry);
        self::emitSnprintfAppendDouble($context, $fn, $fn->getParam(0), 64);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitSnprintfAppend(Context $context, LlvmFunction $fn, string $fmt, Value $arg, int $bufSize): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $buf = $context->builder->alloca($i8->arrayType($bufSize), 1, 'fmtbuf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $sizeT->constInt($bufSize, false),
            $context->builder->pointerCast($context->constantFromString($fmt), $i8p),
            $arg
        );
        $ok = $context->builder->icmp(Builder::INT_SGT, $n, $i32->constInt(0, false));
        $emit = $fn->appendBasicBlock('snp_emit_'.++self::$echoBlockSuffix);
        $done = $fn->appendBasicBlock('snp_done_'.self::$echoBlockSuffix);
        $context->builder->branchIf($ok, $emit, $done);
        $context->builder->positionAtEnd($emit);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_append_bytes'),
            $bufPtr,
            $context->builder->zExt($n, $sizeT)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function emitSnprintfAppendDouble(Context $context, LlvmFunction $fn, Value $arg, int $bufSize): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $buf = $context->builder->alloca($i8->arrayType($bufSize), 1, 'dblbuf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $n = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $sizeT->constInt($bufSize, false),
            $context->builder->pointerCast($context->constantFromString('%.14g'), $i8p),
            $arg
        );
        $ok = $context->builder->icmp(Builder::INT_SGT, $n, $i32->constInt(0, false));
        $emit = $fn->appendBasicBlock('snpd_emit_'.++self::$echoBlockSuffix);
        $done = $fn->appendBasicBlock('snpd_done_'.self::$echoBlockSuffix);
        $context->builder->branchIf($ok, $emit, $done);
        $context->builder->positionAtEnd($emit);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_append_bytes'),
            $bufPtr,
            $context->builder->zExt($n, $sizeT)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function echoFn(Context $context, string $name, $ret, bool $vararg, ...$params): LlvmFunction
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe) {
            return $probe;
        }
        $ft = $context->context->functionType($ret, $vararg, ...$params);
        $fn = $context->module->addFunction($name, $ft);
        $context->registerFunction($name, $fn);

        return $fn;
    }
}
