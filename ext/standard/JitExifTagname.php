<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for exif_tagname() (ext/exif/exif.c; #6105). */
final class JitExifTagname
{
    public static function invoke(Context $context, JITVariable $indexArg): Value
    {
        $index = JitImageTypeArg::lowerImageType(
            $context,
            $indexArg,
            'exif_tagname',
            'index'
        );
        $i64 = $context->getTypeFromString('int64');
        $isNegative = $context->builder->icmp(
            Builder::INT_SLT,
            $index,
            $i64->constInt(0, false)
        );
        $namePtr = self::lookupTagName($context, $index);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $namePtr, $strPtrTy->constNull());
        $shouldFail = $context->builder->or($isNegative, $isNull);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'exiftag_fail');
        $okBlock = BasicBlockHelper::append($context, 'exiftag_ok');
        $doneBlock = BasicBlockHelper::append($context, 'exiftag_done');
        $context->builder->branchIf($shouldFail, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $namePtr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }

    private static function lookupTagName(Context $context, Value $index): Value
    {
        $strPtrTy = $context->getTypeFromString('__string__*');
        $null = $strPtrTy->constNull();
        $result = $null;
        $i64 = $context->getTypeFromString('int64');
        foreach (VmExif::TAG_NAMES as $tag => $name) {
            $eq = $context->builder->icmp(
                Builder::INT_EQ,
                $index,
                $i64->constInt($tag, false)
            );
            $candidate = self::literalString($context, $name);
            $result = $context->builder->select($eq, $candidate, $result);
        }

        return $result;
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $i8p);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }
}
