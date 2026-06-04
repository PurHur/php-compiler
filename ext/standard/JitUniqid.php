<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for uniqid() without AOT C runtime (issue #2219, #5233).
 *
 * Mirrors VmString::uniqid() / former phpc_uniqid.c gettimeofday formatting.
 */
final class JitUniqid
{
    private const TIMESPEC_SIZE = 16;

    private const TIMESPEC_OFF_TV_SEC = 0;

    private const TIMESPEC_OFF_TV_USEC = 8;

    private const USEC_MOD = 0x100000;

    public static function uniqid(Context $context, Value $prefix, Value $moreEntropy): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $hasEntropy = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->zExt($moreEntropy, $i32),
            $i32->constInt(0, false)
        );

        $plainBlock = BasicBlockHelper::append($context, 'uniqid_plain');
        $entropyBlock = BasicBlockHelper::append($context, 'uniqid_entropy');
        $doneBlock = BasicBlockHelper::append($context, 'uniqid_done');
        $context->builder->branchIf($hasEntropy, $entropyBlock, $plainBlock);

        $context->builder->positionAtEnd($plainBlock);
        $plain = self::formatUniqid($context, $prefix, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($entropyBlock);
        $ent = self::formatUniqid($context, $prefix, true);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($plain, $plainBlock);
        $phi->addIncoming($ent, $entropyBlock);

        return $phi;
    }

    private static function formatUniqid(Context $context, Value $prefix, bool $withEntropy): Value
    {
        [$sec32, $usec32] = self::readWallClock($context);
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $map = $context->structFieldMap['__string__'];
        $bufSize = $sizeT->constInt(512, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $prefixLen = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__string__strlen'), $prefix),
            $i32
        );
        $prefixData = $context->builder->structGep($prefix, $map['value']);

        if ($withEntropy) {
            $mix = $context->builder->xor(
                $context->builder->xor(
                    $context->builder->zExt($usec32, $i64),
                    $context->builder->zExt($sec32, $i64)
                ),
                $context->builder->zExt($prefixLen, $i64)
            );
            $ent32 = $context->builder->truncOrBitCast(
                $context->builder->unsigendRem(
                    $mix,
                    $i64->constInt(100000000, false)
                ),
                $i32
            );
            $fmtPtr = $context->builder->pointerCast(
                $context->constantFromString('%.*s%08x%05x.%08u'),
                $charPtr
            );
            $written = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufChar,
                $bufSize,
                $fmtPtr,
                $prefixLen,
                $prefixData,
                $sec32,
                $usec32,
                $ent32
            );
        } else {
            $fmtPtr = $context->builder->pointerCast(
                $context->constantFromString('%.*s%08x%05x'),
                $charPtr
            );
            $written = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufChar,
                $bufSize,
                $fmtPtr,
                $prefixLen,
                $prefixData,
                $sec32,
                $usec32
            );
        }

        $len = $context->builder->zExt($written, $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $result;
    }

    /**
     * @return array{0: Value, 1: Value} tv_sec and tv_usec % 0x100000 as i32
     */
    private static function readWallClock(Context $context): array
    {
        self::ensureGettimeofday($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero64 = $i64->constInt(0, false);
        $zero32 = $i32->constInt(0, false);

        $tv = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $sizeT->constInt(self::TIMESPEC_SIZE, false)
        );
        $tvI8 = $context->builder->pointerCast($tv, $i8p);
        $status = $context->builder->call(
            $context->lookupFunction('gettimeofday'),
            $tvI8,
            $i8p->constNull()
        );
        $failed = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->truncOrBitCast($status, $i32),
            $zero32
        );

        $secRaw = self::loadI64At($context, $tv, self::TIMESPEC_OFF_TV_SEC);
        $usecRaw = self::loadI64At($context, $tv, self::TIMESPEC_OFF_TV_USEC);
        $context->builder->call($context->lookupFunction('__mm__free'), $tv);

        $sec = $context->builder->truncOrBitCast(
            $context->builder->select($failed, $zero64, $secRaw),
            $i32
        );
        $usecMasked = $context->builder->and(
            $usecRaw,
            $i64->constInt(self::USEC_MOD - 1, false)
        );
        $usec = $context->builder->truncOrBitCast(
            $context->builder->select($failed, $zero64, $usecMasked),
            $i32
        );

        return [$sec, $usec];
    }

    private static function tvSlot(Context $context, Value $base, int $offset): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $ptr = $context->builder->gep($base, $i8->constInt($offset, false));

        return $context->builder->pointerCast($ptr, $i64->pointerType(0));
    }

    private static function loadI64At(Context $context, Value $base, int $offset): Value
    {
        return $context->builder->load(self::tvSlot($context, $base, $offset));
    }

    private static function ensureGettimeofday(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');

        try {
            $context->lookupFunction('gettimeofday');
        } catch (\Throwable $e) {
            $ft = $context->context->functionType($i32, false, $i8p, $i8p);
            $fn = $context->module->addFunction('gettimeofday', $ft);
            $context->registerFunction('gettimeofday', $fn);
        }
    }
}
