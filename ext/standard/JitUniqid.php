<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGettimeofday;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for uniqid() without AOT C runtime (issue #2219, #5233, #6722).
 *
 * Mirrors VmString::uniqid(); wall clock via {@see StringGettimeofday} (no local gettimeofday extern).
 */
final class JitUniqid
{
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
            $entU32 = $context->builder->truncOrBitCast(
                $context->builder->unsigendRem(
                    $mix,
                    $i64->constInt(0x100000000, false)
                ),
                $i32
            );
            $doubleTy = $context->getTypeFromString('double');
            $entF64 = $context->builder->uitofp($entU32, $doubleTy);
            $maxU32 = $context->builder->uitofp($i64->constInt(0xFFFFFFFF, false), $doubleTy);
            $seed = $context->builder->fmul(
                $context->builder->fdiv($entF64, $maxU32),
                $doubleTy->constFloat(10.0)
            );
            $fmtPtr = $context->builder->pointerCast(
                $context->constantFromString('%.*s%08x%05x%.8F'),
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
                $seed
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
        return StringGettimeofday::readSecUsec($context, self::USEC_MOD);
    }
}
