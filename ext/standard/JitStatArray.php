<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StatArrayRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for stat()/lstat() via {@see __phpc_stat} (issue #1197, #32651, #35656). */
final class JitStatArray
{
    private static int $seq = 0;

    /** @var list<array{index: int, field: int|null, key: string}> */
    private const STAT_FIELDS = [
        ['index' => 0, 'field' => StatFieldsJitHelper::FIELD_DEV, 'key' => 'dev'],
        ['index' => 1, 'field' => StatFieldsJitHelper::FIELD_INO, 'key' => 'ino'],
        ['index' => 2, 'field' => StatFieldsJitHelper::FIELD_MODE, 'key' => 'mode'],
        ['index' => 3, 'field' => null, 'key' => 'nlink'],
        ['index' => 4, 'field' => StatFieldsJitHelper::FIELD_UID, 'key' => 'uid'],
        ['index' => 5, 'field' => StatFieldsJitHelper::FIELD_GID, 'key' => 'gid'],
        ['index' => 6, 'field' => null, 'key' => 'rdev'],
        ['index' => 7, 'field' => StatFieldsJitHelper::FIELD_SIZE, 'key' => 'size'],
        ['index' => 8, 'field' => StatFieldsJitHelper::FIELD_ATIME, 'key' => 'atime'],
        ['index' => 9, 'field' => StatFieldsJitHelper::FIELD_MTIME, 'key' => 'mtime'],
        ['index' => 10, 'field' => StatFieldsJitHelper::FIELD_CTIME, 'key' => 'ctime'],
        ['index' => 11, 'field' => null, 'key' => 'blksize'],
        ['index' => 12, 'field' => null, 'key' => 'blocks'],
    ];

    /** @return Value */
    public static function invoke(Context $context, Value $pathStr, bool $lstat): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return self::invokeNestedJitLibcLeaf($context, $pathStr, $lstat);
        }

        StatArrayRuntime::ensureLinked($context);

        $tag = ($lstat ? 'lstat' : 'stat').(string) ++self::$seq;
        $i32 = $context->getTypeFromString('int32');
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $ht = $context->builder->call(
            $context->lookupFunction('__phpc_stat'),
            $pathStr,
            $i32->constInt($lstat ? 1 : 0, false)
        );
        $failed = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtrTy->constNull());
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        // Single slot for both arms — peer JitStat::pathLongFieldBoxed / filesize() (#35656).
        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $context->builder->positionAtEnd($failBlock);
        JitStat::warnPathStatArrayFailed($context, $pathStr, $lstat ? 'lstat' : 'stat', $lstat);
        JitValueBox::writeBool($context, $resultSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $resultPtr, $ht);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $resultPtr;
    }

    /**
     * NestedJIT leaf for StatArrayJitHelper @\stat/@\lstat — libc struct stat, not __phpc_stat
     * (peer JitFsGlob::collectList / #27235).
     *
     * @return Value
     */
    private static function invokeNestedJitLibcLeaf(Context $context, Value $pathStr, bool $lstat): Value
    {
        $tag = ($lstat ? 'lstat' : 'stat').'_leaf_'.(string) ++self::$seq;
        $mode = JitStatKernel::mode($context, $pathStr, $lstat);
        $i32 = $context->getTypeFromString('int32');
        $failed = $context->builder->icmp(Builder::INT_SLT, $mode, $i32->constInt(0, true));
        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $buildBlock = BasicBlockHelper::append($context, $tag.'_build');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($failed, $failBlock, $buildBlock);

        $resultSlot = JitValueBox::alloc($context);
        $resultPtr = JitValueBox::pointer($context, $resultSlot);

        $context->builder->positionAtEnd($failBlock);
        JitStat::warnPathStatArrayFailed($context, $pathStr, $lstat ? 'lstat' : 'stat', $lstat);
        JitValueBox::writeBool($context, $resultSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($buildBlock);
        $ht = self::buildStatHashtableFromLibc($context, $pathStr, $lstat, $tag);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $resultPtr, $ht);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $resultPtr;
    }

    /** @return Value __hashtable__* */
    private static function buildStatHashtableFromLibc(Context $context, Value $pathStr, bool $lstat, string $tag): Value
    {
        $ht = HashTableHelper::alloc($context);
        $i64 = $context->getTypeFromString('int64');
        $useLstat = $i64->constInt($lstat ? 1 : 0, false);
        $setLongAt = $context->lookupFunction('__hashtable__setLongAt');
        $setStringKeyLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $i64Ty = $context->getTypeFromString('int64');

        foreach (self::STAT_FIELDS as $spec) {
            $index = $spec['index'];
            if (null !== $spec['field']) {
                $val = JitStatKernel::longField(
                    $context,
                    $pathStr,
                    $useLstat,
                    $i64->constInt($spec['field'], false)
                );
            } else {
                $val = $i64->constInt(0, false);
            }
            $idxConst = $i64Ty->constInt($index, false);
            $context->builder->call($setLongAt, $ht, $idxConst, $val);
            $keyStr = self::literalString($context, $spec['key']);
            $context->builder->call($setStringKeyLong, $ht, $keyStr, $val);
        }

        return $ht;
    }

    private static function literalString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($text), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $cstr
        );
    }
}
