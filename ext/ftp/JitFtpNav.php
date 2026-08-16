<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFtpNav;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for ftp_pasv/chdir/cdup/pwd via FtpNavJitHelper (#31379).
 */
final class JitFtpNav
{
    public static function invokePasv(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            return self::emitAce($context, 'ftp_pasv() expects exactly 2 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_pasv');
        $enable = JitBoolArg::lowerCoerceZParamBool($context, $args[1], 'ftp_pasv', 'enable', 2);
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpNav::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_pasv'),
            $handle,
            $enable
        );

        return self::boolFromI1($context, $ok);
    }

    public static function invokeChdir(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            return self::emitAce($context, 'ftp_chdir() expects exactly 2 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_chdir');
        $directory = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_chdir',
            1,
            'directory'
        );
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpNav::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_chdir'),
            $handle,
            $directory
        );

        return self::boolFromI1($context, $ok);
    }

    public static function invokeCdup(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return self::emitAce($context, 'ftp_cdup() expects exactly 1 argument, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_cdup');
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpNav::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_cdup'),
            $handle
        );

        return self::boolFromI1($context, $ok);
    }

    public static function invokePwd(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return self::emitAce($context, 'ftp_pwd() expects exactly 1 argument, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_pwd');
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpNav::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $path = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_pwd'),
            $handle
        );
        $len = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $path
        );
        $i64 = $context->getTypeFromString('int64');
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $failBb = BasicBlockHelper::append($context, 'ftp_pwd_fail');
        $okBb = BasicBlockHelper::append($context, 'ftp_pwd_ok');
        $doneBb = BasicBlockHelper::append($context, 'ftp_pwd_done');
        $context->builder->branchIf($empty, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $failSlot = JitValueBox::alloc($context);
        $failPtr = JitValueBox::pointer($context, $failSlot);
        JitValueBox::writeBool(
            $context,
            $failSlot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $okPtr,
            $path
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $phi = $context->builder->phi($ptrTy);
        $phi->addIncoming($failPtr, $failTail);
        $phi->addIncoming($okPtr, $okTail);

        return $phi;
    }

    private static function connectionHandle(Context $context, JITVariable $arg, string $function): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, $function);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $loaded
            );
            $voidp = $context->getTypeFromString('void')->pointerType(0);
            $i64 = $context->getTypeFromString('int64');

            return $context->builder->ptrToInt(
                $context->builder->pointerCast($obj, $voidp),
                $i64
            );
        }
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            $function.'(): Argument #1 ($ftp) must be of type FTP\\Connection, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }

    private static function boolFromI1(Context $context, Value $ok): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $ok);

        return $ptr;
    }

    private static function emitAce(Context $context, string $message): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError($context, $message);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $ptr;
    }
}
