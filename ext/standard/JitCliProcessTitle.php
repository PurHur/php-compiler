<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for cli_get/set_process_title() (#8138, phase 2 of #5155).
 *
 * PHP-owned title buffer in module globals; Linux kernel comm via libc prctl(PR_SET_NAME).
 * php-src: ext/standard/cli_ops.c
 */
final class JitCliProcessTitle
{
    private const G_TITLE_PTR = 'phpc_cli_process_title_ptr';

    private const G_TITLE_LEN = 'phpc_cli_process_title_len';

    /** Linux TASK_COMM_LEN includes terminating NUL (16 bytes total). */
    private const COMM_MAX_BYTES = 15;

    private const PR_SET_NAME = 15;

    public static function set(Context $context, JITVariable $titleArg): Value
    {
        self::ensureGlobals($context);
        self::ensureLibc($context);

        $title = JitStringBuiltinArg::lower($context, $titleArg, 'cli_set_process_title', 0, 'title');
        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $one = $i64->constInt(1, false);

        $titleLen = $context->builder->load($context->builder->structGep($title, $strMap['length']));
        $titleBytes = $context->builder->structGep($title, $strMap['value']);
        $titleCStr = $context->builder->pointerCast($titleBytes, $i8p);

        $bufLen = $context->builder->add($titleLen, $one);
        $buf = $context->builder->call($context->lookupFunction('malloc'), $bufLen);
        $context->intrinsic->memcpy($buf, $titleCStr, $titleLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($buf, $titleLen)
        );

        $ptrGlobal = $context->module->getNamedGlobal(self::G_TITLE_PTR);
        $lenGlobal = $context->module->getNamedGlobal(self::G_TITLE_LEN);
        $context->builder->store($buf, $ptrGlobal);
        $context->builder->store($titleLen, $lenGlobal);

        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $commLen = $context->builder->call(
            $context->lookupFunction('strlen'),
            $titleCStr
        );
        $commMax = $i64->constInt(self::COMM_MAX_BYTES, false);
        $copyLen = self::minI64($context, $commLen, $commMax);
        $commBufLen = $context->builder->add($copyLen, $one);
        $commBuf = $context->builder->alloca($i8, $commBufLen, 'cli_comm');
        $commCStr = $context->builder->pointerCast($commBuf, $i8p);
        $context->intrinsic->memcpy($commCStr, $titleCStr, $copyLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($commCStr, $copyLen)
        );

        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('prctl'),
            $i32->constInt(self::PR_SET_NAME, false),
            $commCStr
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(1, false));

        return $ptr;
    }

    public static function get(Context $context): Value
    {
        self::ensureGlobals($context);

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI64 = $i64->constInt(0, false);

        $ptrGlobal = $context->module->getNamedGlobal(self::G_TITLE_PTR);
        $lenGlobal = $context->module->getNamedGlobal(self::G_TITLE_LEN);
        $titlePtr = $context->builder->load($ptrGlobal);
        $titleLen = $context->builder->load($lenGlobal);

        $slot = JitValueBox::alloc($context);
        $out = JitValueBox::pointer($context, $slot);

        $empty = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $titleLen, $zeroI64),
            $context->builder->icmp(Builder::INT_EQ, $titlePtr, $i8p->constNull())
        );

        $emptyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $zeroI64,
            $i8p->constNull()
        );
        $storedStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $titleLen,
            $titlePtr
        );
        $resultStr = $context->builder->select($empty, $emptyStr, $storedStr);

        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $out,
            $resultStr
        );

        return $out;
    }

    private static function ensureGlobals(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        if (null === $context->module->getNamedGlobal(self::G_TITLE_PTR)) {
            $g = $context->module->addGlobal($i8p, self::G_TITLE_PTR);
            $g->setInitializer($i8p->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::G_TITLE_LEN)) {
            $g = $context->module->addGlobal($i64, self::G_TITLE_LEN);
            $g->setInitializer($i64->constInt(0, false));
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        // malloc after LibcExtern always-on drop (#32273) — canonical i8*/size_t,
        // not i64 size (malloc.1 class, #31894 / #32122).
        LibcExtern::ensureMallocFamily($context);
        LibcExtern::ensureStrlenDecl($context);

        try {
            $context->lookupFunction('prctl');
        } catch (\Throwable) {
            $ft = $context->context->functionType($i32, false, $i32, $i8p);
            $fn = $context->module->addFunction('prctl', $ft);
            $context->registerFunction('prctl', $fn);
        }
    }

    private static function minI64(Context $context, Value $a, Value $b): Value
    {
        $cmp = $context->builder->icmp(Builder::INT_ULT, $a, $b);

        return $context->builder->select($cmp, $a, $b);
    }
}
