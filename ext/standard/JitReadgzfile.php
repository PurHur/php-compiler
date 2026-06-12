<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GzStreamIo;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for readgzfile() — gzopen + passthru + close (#4657 phase 2). */
final class JitReadgzfile
{
    /** @return Value int bytes written or boolean false */
    public static function invoke(Context $context, Value $pathStr, Value $useIncludePath): Value
    {
        GzStreamIo::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
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

        $failBlock = BasicBlockHelper::append($context, 'readgzfile_fail');
        $workBlock = BasicBlockHelper::append($context, 'readgzfile_work');
        $doneBlock = BasicBlockHelper::append($context, 'readgzfile_done');
        $context->builder->branchIf($openFailed, $failBlock, $workBlock);

        $context->builder->positionAtEnd($workBlock);
        $bytesLong = $context->builder->call(
            $context->lookupFunction('__compiler_gz_passthru'),
            $handleLong
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_gzclose'),
            $handleLong
        );
        $passthruFailed = $context->builder->icmp(Builder::INT_SLT, $bytesLong, $zero);
        $okBlock = BasicBlockHelper::append($context, 'readgzfile_ok');
        $context->builder->branchIf($passthruFailed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $bytesLong);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
