<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for sys_getloadavg() via libc getloadavg(3) (#3464).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(sys_getloadavg)
 */
final class JitSysGetloadavg
{
    private const LOAD_COUNT = 3;

    private static int $blockSerial = 0;

    public static function invoke(Context $context): Value
    {
        self::ensureLibcGetloadavg($context);
        self::ensureHashtableHelpers($context);

        $double = $context->getTypeFromString('double');
        $bufType = $double->arrayType(self::LOAD_COUNT);
        $buf = $context->builder->alloca($bufType, 1, 'sys_getloadavg_buf');
        $dblPtr = $context->builder->pointerCast($buf, $double->pointerType(0));

        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('getloadavg'),
            $dblPtr,
            $i32->constInt(self::LOAD_COUNT, false)
        );
        $failed = $context->builder->icmp(
            Builder::INT_EQ,
            $ret,
            $i32->constInt(-1, true)
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'sys_getloadavg_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'sys_getloadavg_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'sys_getloadavg_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $htNull = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtrTy->constNull());
        $allocFailBlock = BasicBlockHelper::append($context, 'sys_getloadavg_alloc_fail_'.$id);
        $fillBlock = BasicBlockHelper::append($context, 'sys_getloadavg_fill_'.$id);
        $context->builder->branchIf($htNull, $allocFailBlock, $fillBlock);

        $context->builder->positionAtEnd($allocFailBlock);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($fillBlock);
        $sizeT = $context->getTypeFromString('size_t');
        $setDouble = $context->lookupFunction('__hashtable__setDoubleAt');
        for ($i = 0; $i < self::LOAD_COUNT; ++$i) {
            $elemPtr = $context->builder->gep(
                $buf,
                $i32->constInt(0, false),
                $i32->constInt($i, false)
            );
            $load = $context->builder->load($elemPtr);
            $context->builder->call(
                $setDouble,
                $ht,
                $sizeT->constInt($i, false),
                $load
            );
        }
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function ensureLibcGetloadavg(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $double = $context->getTypeFromString('double');
        $dblPtr = $double->pointerType(0);

        self::ensureExternal(
            $context,
            'getloadavg',
            $context->context->functionType($i32, false, $dblPtr, $i32)
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $sizeT = $context->getTypeFromString('size_t');
        $double = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__alloc', $htPtr, []],
            ['__hashtable__setDoubleAt', $voidTy, [$htPtr, $sizeT, $double]],
            ['__value__writeBool', $voidTy, [$valuePtr, $i32]],
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal(
                $context,
                $name,
                $context->context->functionType($ret, false, ...$params)
            );
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
