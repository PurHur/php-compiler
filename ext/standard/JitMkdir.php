<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StatPathRuntime;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for mkdir() via __compiler_mkdir (libc mkdir(2), optional recursive). */
final class JitMkdir
{
    private static int $blockSerial = 0;

    /** @return Value */
    public static function invoke(Context $context, Value $pathStr, Value $modeLong, Value $recursiveBool): Value
    {
        StringTriggerErrorJit::implement($context);
        StatPathRuntime::ensureLinked($context);
        $i32 = $context->getTypeFromString('int32');
        $recursiveI32 = $context->builder->zext($recursiveBool, $i32);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_mkdir'),
            $pathStr,
            $modeLong,
            $recursiveI32
        );
        $one = $i32->constInt(1, false);
        $failed = $context->builder->icmp(Builder::INT_NE, $ret, $one);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'mkdir_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'mkdir_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'mkdir_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $alreadyDir = $context->builder->call(
            $context->lookupFunction(StatPathRuntime::FN_IS_DIR),
            $pathStr
        );
        $isDir = $context->builder->icmp(Builder::INT_NE, $alreadyDir, $i32->constInt(0, false));
        $existsBlock = BasicBlockHelper::append($context, 'mkdir_exists_'.$id);
        $missingBlock = BasicBlockHelper::append($context, 'mkdir_missing_'.$id);
        $warnDoneBlock = BasicBlockHelper::append($context, 'mkdir_warn_done_'.$id);
        $context->builder->branchIf($isDir, $existsBlock, $missingBlock);

        $context->builder->positionAtEnd($existsBlock);
        JitBuiltinWarning::emit($context, 'mkdir(): File exists');
        $context->builder->branch($warnDoneBlock);

        $context->builder->positionAtEnd($missingBlock);
        JitBuiltinWarning::emit($context, 'mkdir(): No such file or directory');
        $context->builder->branch($warnDoneBlock);

        $context->builder->positionAtEnd($warnDoneBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $one);
    }
}
