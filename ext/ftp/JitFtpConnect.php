<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFtpConnect;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for ftp_connect() via FtpConnectJitHelper (#27393).
 *
 * Resolve/coerce args before NestedJIT ensureLinked — appending type-check blocks after
 * NestedJIT can orphan the user insert block under thin AOT (peer JitSocketClose / #27394).
 *
 * php-src: ext/ftp/ftp.c — PHP_FUNCTION(ftp_connect)
 */
final class JitFtpConnect
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            return self::emitArgumentCountError($context, $argc);
        }

        $hostname = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[0],
            'ftp_connect',
            0,
            'hostname'
        );

        $i64 = $context->getTypeFromString('int64');
        $port = $i64->constInt(21, false);
        if ($argc >= 2) {
            $port = JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[1],
                'ftp_connect',
                2,
                'port'
            );
        }
        $timeout = $i64->constInt(90, false);
        if ($argc >= 3) {
            $timeout = JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[2],
                'ftp_connect',
                3,
                'timeout'
            );
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpConnect::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }

        $fd = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_connect_fd'),
            $hostname,
            $port,
            $timeout
        );
        $zero = $i64->constInt(0, false);
        $ok = $context->builder->icmp(Builder::INT_SGE, $fd, $zero);

        $failBb = BasicBlockHelper::append($context, 'ftp_connect_fail');
        $okBb = BasicBlockHelper::append($context, 'ftp_connect_ok');
        $doneBb = BasicBlockHelper::append($context, 'ftp_connect_done');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool(
            $context,
            $falseSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $objPtr = self::allocateConnectionObject($context);
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $objAddr = $context->builder->ptrToInt(
            $context->builder->pointerCast($objPtr, $voidp),
            $i64
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_ftp_connect_register'),
            $objAddr,
            $fd,
            $port,
            $timeout
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $objPtr
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($falsePtr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function allocateConnectionObject(Context $context): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('FTP\\Connection');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            'ftp_connect() expects from 1 to 3 arguments, '.$argc.' given'
        );
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
