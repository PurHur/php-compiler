<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for mime_content_type() via {@see \PHPCompiler\JIT\Builtin\MimeContentTypeRuntime}. */
final class JitMimeContentType
{
    public static function invoke(Context $context, Value $pathStr): Value
    {
        \PHPCompiler\JIT\Builtin\MimeContentTypeRuntime::ensureLinked($context);

        $mime = $context->builder->call(
            $context->lookupFunction('__compiler_mime_content_type'),
            $pathStr
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $mime, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'mime_fail');
        $okBlock = BasicBlockHelper::append($context, 'mime_ok');
        $doneBlock = BasicBlockHelper::append($context, 'mime_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $mime
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
