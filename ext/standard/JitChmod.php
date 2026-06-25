<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for chmod() via libc chmod(2). */
final class JitChmod
{
    private static int $blockSerial = 0;

    /** @return Value
     * true when chmod(2) returns 0 */
    public static function invoke(Context $context, Value $pathStr, Value $modeI32): Value
    {
        StringTriggerErrorJit::implement($context);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('chmod'),
            $pathPtr,
            $modeI32
        );
        $zero = $i32->constInt(0, false);
        $failed = $context->builder->icmp(Builder::INT_NE, $ret, $zero);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'chmod_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'chmod_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'chmod_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        self::emitWarning($context, 'chmod(): No such file or directory');
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->icmp(Builder::INT_EQ, $ret, $zero);
    }

    private static function emitWarning(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }
}
