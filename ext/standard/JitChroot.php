<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringTriggerErrorJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for chroot() via libc chroot(2) (#3500, #29360). */
final class JitChroot
{
    private static int $blockSerial = 0;

    /** @return Value true when chroot(2) returns 0 */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        StringTriggerErrorJit::implement($context);
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($pathStr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('chroot'),
            $pathPtr
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(0, false));
        $id = (string) (++self::$blockSerial);
        $failBb = BasicBlockHelper::append($context, 'chroot_fail_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'chroot_done_'.$id);
        $context->builder->branchIf($ok, $doneBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        // php-src dir.c — Zend cites errno 2 for this failure text (#29360).
        $msg = 'chroot(): No such file or directory (errno 2)';
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($msg), $i8p);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen($msg), false),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ok;
    }
}
