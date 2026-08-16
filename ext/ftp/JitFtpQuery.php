<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFtpQuery;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for ftp_size/mdtm/systype/nlist via FtpQueryJitHelper (#31380).
 */
final class JitFtpQuery
{
    public static function invokeSize(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            return self::emitAce($context, 'ftp_size() expects exactly 2 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_size');
        $filename = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_size',
            1,
            'filename'
        );
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpQuery::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $size = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_size'),
            $handle,
            $filename
        );

        return self::longResult($context, $size);
    }

    public static function invokeMdtm(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            return self::emitAce($context, 'ftp_mdtm() expects exactly 2 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_mdtm');
        $filename = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_mdtm',
            1,
            'filename'
        );
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpQuery::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $stamp = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_mdtm'),
            $handle,
            $filename
        );

        return self::longResult($context, $stamp);
    }

    public static function invokeSystype(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            return self::emitAce($context, 'ftp_systype() expects exactly 1 argument, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_systype');
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpQuery::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $sys = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_systype'),
            $handle
        );
        $len = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $sys
        );
        $i64 = $context->getTypeFromString('int64');
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));
        $failBb = BasicBlockHelper::append($context, 'ftp_systype_fail');
        $okBb = BasicBlockHelper::append($context, 'ftp_systype_ok');
        $doneBb = BasicBlockHelper::append($context, 'ftp_systype_done');
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
            $sys
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

    public static function invokeNlist(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            return self::emitAce($context, 'ftp_nlist() expects exactly 2 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_nlist');
        $directory = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_nlist',
            1,
            'directory'
        );
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpQuery::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $htRaw = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_nlist'),
            $handle,
            $directory
        );
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $failBb = BasicBlockHelper::append($context, 'ftp_nlist_fail');
        $okBb = BasicBlockHelper::append($context, 'ftp_nlist_ok');
        $doneBb = BasicBlockHelper::append($context, 'ftp_nlist_done');
        $context->builder->branchIf($failed, $failBb, $okBb);

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
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $okSlot = JitValueBox::alloc($context);
        $okPtr = JitValueBox::pointer($context, $okSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $okPtr,
            $ht
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

    private static function longResult(Context $context, Value $long): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeLong($context, $slot, $long);

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
