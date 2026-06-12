<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GzStreamIo;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gzfile() — gzopen + read-all + line split (#4657 phase 2). */
final class JitGzfile
{
    /** @return Value line array or boolean false */
    public static function invoke(Context $context, Value $pathStr, Value $useIncludePath): Value
    {
        GzStreamIo::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $nullStr = $strPtrTy->constNull();
        $zero = $i64->constInt(0, false);
        $modeStr = $context->builder->load($context->constantStringFromString('rb'));

        $handleLong = $context->builder->call(
            $context->lookupFunction('__compiler_gzopen'),
            $pathStr,
            $modeStr,
            $useIncludePath
        );
        $openFailed = $context->builder->icmp(Builder::INT_SLT, $handleLong, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'gzfile_fail');
        $readBlock = BasicBlockHelper::append($context, 'gzfile_read');
        $doneBlock = BasicBlockHelper::append($context, 'gzfile_done');
        $context->builder->branchIf($openFailed, $failBlock, $readBlock);

        $context->builder->positionAtEnd($readBlock);
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_gz_read_all'),
            $handleLong
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_gzclose'),
            $handleLong
        );
        $readFailed = $context->builder->icmp(Builder::INT_EQ, $contents, $nullStr);
        $okBlock = BasicBlockHelper::append($context, 'gzfile_ok');
        $context->builder->branchIf($readFailed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        $ht = JitFile::splitLines($context, $contents, $zero);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $ht);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
