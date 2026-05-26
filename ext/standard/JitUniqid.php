<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for uniqid() without MCJIT C bitcode (issue #2219).
 */
final class JitUniqid
{
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
        $plain = self::snprintfUniqid($context, $prefix, '%.*s%08x00000', false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($entropyBlock);
        $ent = self::snprintfUniqid($context, $prefix, '%.*s%08x00000.%08x', true);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strPtr = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($plain, $plainBlock);
        $phi->addIncoming($ent, $entropyBlock);

        return $phi;
    }

    private static function snprintfUniqid(
        Context $context,
        Value $prefix,
        string $fmt,
        bool $withEntropy
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $map = $context->structFieldMap['__string__'];
        $bufSize = $sizeT->constInt(128, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $sec = JitDate::time($context);
        $sec32 = $context->builder->truncOrBitCast($sec, $i32);
        $prefixLen = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__string__strlen'), $prefix),
            $i32
        );
        $prefixData = $context->builder->structGep($prefix, $map['value']);
        $fmtPtr = $context->builder->pointerCast($context->constantFromString($fmt), $charPtr);

        if ($withEntropy) {
            $ent32 = $context->builder->truncOrBitCast(
                $context->builder->and($sec, $i64->constInt(0x5F5E0FF, false)),
                $i32
            );
            $written = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufChar,
                $bufSize,
                $fmtPtr,
                $prefixLen,
                $prefixData,
                $sec32,
                $ent32
            );
        } else {
            $written = $context->builder->call(
                $context->lookupFunction('snprintf'),
                $bufChar,
                $bufSize,
                $fmtPtr,
                $prefixLen,
                $prefixData,
                $sec32
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
}
