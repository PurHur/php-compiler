<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringDir;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ValueEchoSupport;
use PHPLLVM\Value;

/**
 * SSOT for JIT resource handle string formatting (#11420, #9976).
 *
 * php-src: Zend/zend_operators.c convert_to_string on IS_RESOURCE
 * JIT trampoline: {@see \PHPCompiler\JIT\JitResourceIdString}
 */
final class VmResourceIdString
{
    public static function formatNativeLong(Context $context, Value $longVal): Value
    {
        StringDir::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $handle = $longVal->typeOf() === $i64
            ? $longVal
            : $context->builder->zExt($longVal, $i64);
        $isRes = VmValueCompare::nativeLongIsResource($context, $handle);

        $tag = 'resid_'.(string) spl_object_id($context);
        $plainBlock = BasicBlockHelper::append($context, $tag.'_plain');
        $resBlock = BasicBlockHelper::append($context, $tag.'_res');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');

        $context->builder->branchIf($isRes, $resBlock, $plainBlock);

        $context->builder->positionAtEnd($plainBlock);
        $plainStr = self::snprintf($context, $handle, '%lld');
        $plainEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($resBlock);
        $resStr = self::snprintf($context, $handle, ValueEchoSupport::RESOURCE_FORMAT);
        $resEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $strTy = $context->getTypeFromString('__string__*');
        $phi = $context->builder->phi($strTy);
        $phi->addIncoming($plainStr, $plainEnd);
        $phi->addIncoming($resStr, $resEnd);

        return $phi;
    }

    private static function snprintf(Context $context, Value $handle, string $format): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(32, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString($format),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $handle
        );
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }
}
