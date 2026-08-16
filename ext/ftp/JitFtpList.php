<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFtpList;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for ftp_rawlist/mlsd via FtpListJitHelper (#31428).
 */
final class JitFtpList
{
    public static function invokeRawlist(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            return self::emitAce(
                $context,
                'ftp_rawlist() expects at least 2 arguments and at most 3, '.$argc.' given'
            );
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_rawlist');
        $directory = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_rawlist',
            1,
            'directory'
        );
        $recursive = $context->getTypeFromString('int1')->constInt(0, false);
        if ($argc >= 3) {
            $recursive = JitBoolArg::lowerCoerceZParamBool(
                $context,
                $args[2],
                'ftp_rawlist',
                'recursive',
                3
            );
        }
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpList::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $htRaw = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_rawlist'),
            $handle,
            $directory,
            $recursive
        );

        return self::arrayOrFalse($context, $htRaw, 'ftp_rawlist');
    }

    public static function invokeMlsd(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            return self::emitAce($context, 'ftp_mlsd() expects exactly 2 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_mlsd');
        $directory = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_mlsd',
            1,
            'directory'
        );
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpList::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $htRaw = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_mlsd'),
            $handle,
            $directory
        );

        return self::arrayOrFalse($context, $htRaw, 'ftp_mlsd');
    }

    private static function arrayOrFalse(Context $context, Value $htRaw, string $tag): Value
    {
        $failed = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $failBb = BasicBlockHelper::append($context, $tag.'_fail');
        $okBb = BasicBlockHelper::append($context, $tag.'_ok');
        $doneBb = BasicBlockHelper::append($context, $tag.'_done');
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
