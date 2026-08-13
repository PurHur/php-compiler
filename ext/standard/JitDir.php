<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringDirFactory;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\DirectoryJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for dir() — Directory factory via scandir snapshot (#30757).
 *
 * php-src: ext/standard/dir.c — PHP_FUNCTION(dir)
 */
final class JitDir
{
    public static function invoke(Context $context, Value $pathStr): Value
    {
        $ht = StringDirFactory::invokeSnapshot($context, $pathStr);
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dir_after_snapshot');

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $map = $context->structFieldMap['__hashtable__'];
        $n = $context->builder->load($context->builder->structGep($ht, $map['numElements']));
        $i64 = $context->getTypeFromString('int64');
        $n64 = $context->builder->truncOrBitCast($n, $i64);
        // Empty HT from failed scandir — warn + false. Empty real dirs still have . and ..
        $failed = $context->builder->icmp(Builder::INT_EQ, $n64, $i64->constInt(0, false));

        $failBb = BasicBlockHelper::append($context, 'dir_snap_fail');
        $okBb = BasicBlockHelper::append($context, 'dir_snap_ok');
        $doneBb = BasicBlockHelper::append($context, 'dir_snap_done');
        $context->builder->branchIf($failed, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        // Empty path already returns empty without warning from scandir; non-empty missing path warns.
        $len = $context->builder->call($context->lookupFunction('__string__strlen'), $pathStr);
        $nonEmpty = $context->builder->icmp(Builder::INT_NE, $len, $i64->constInt(0, false));
        $warnBb = BasicBlockHelper::append($context, 'dir_snap_warn');
        $noWarnBb = BasicBlockHelper::append($context, 'dir_snap_nowarn');
        $context->builder->branchIf($nonEmpty, $warnBb, $noWarnBb);

        $context->builder->positionAtEnd($warnBb);
        JitBuiltinWarning::emitPathOpenFailed($context, $pathStr, 'dir');
        $context->builder->branch($noWarnBb);

        $context->builder->positionAtEnd($noWarnBb);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $obj = DirectoryJitHelper::allocateFromSnapshot($context, $pathStr, $ht);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }
}
