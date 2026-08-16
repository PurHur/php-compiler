<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ftp;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFtpTransfer;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for ftp_get/put/fget/fput via FtpTransferJitHelper (#31429).
 */
final class JitFtpTransfer
{
    public static function invokeGet(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 5) {
            return self::emitAce($context, 'ftp_get() expects from 3 to 5 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_get');
        $local = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_get',
            1,
            'local_filename'
        );
        $remote = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[2],
            'ftp_get',
            2,
            'remote_filename'
        );
        $mode = self::modeArg($context, $args, $argc, 3, 'ftp_get');
        $offset = self::offsetArg($context, $args, $argc, 4, 'ftp_get');
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpTransfer::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_get'),
            $handle,
            $local,
            $remote,
            $mode,
            $offset
        );

        return self::boolFromI1($context, $ok);
    }

    public static function invokePut(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 5) {
            return self::emitAce($context, 'ftp_put() expects from 3 to 5 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_put');
        $remote = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_put',
            1,
            'remote_filename'
        );
        $local = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[2],
            'ftp_put',
            2,
            'local_filename'
        );
        $mode = self::modeArg($context, $args, $argc, 3, 'ftp_put');
        $offset = self::offsetArg($context, $args, $argc, 4, 'ftp_put');
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpTransfer::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_put'),
            $handle,
            $remote,
            $local,
            $mode,
            $offset
        );

        return self::boolFromI1($context, $ok);
    }

    public static function invokeFget(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 5) {
            return self::emitAce($context, 'ftp_fget() expects from 3 to 5 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_fget');
        $i64 = $context->getTypeFromString('int64');
        $stream = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'ftp_fget() stream'),
            $i64
        );
        $remote = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[2],
            'ftp_fget',
            2,
            'remote_filename'
        );
        $mode = self::modeArg($context, $args, $argc, 3, 'ftp_fget');
        $offset = self::offsetArg($context, $args, $argc, 4, 'ftp_fget');
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpTransfer::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_fget'),
            $handle,
            $stream,
            $remote,
            $mode,
            $offset
        );

        return self::boolFromI1($context, $ok);
    }

    public static function invokeFput(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 5) {
            return self::emitAce($context, 'ftp_fput() expects from 3 to 5 arguments, '.$argc.' given');
        }
        $handle = self::connectionHandle($context, $args[0], 'ftp_fput');
        $remote = JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $args[1],
            'ftp_fput',
            1,
            'remote_filename'
        );
        $i64 = $context->getTypeFromString('int64');
        $stream = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[2], 'ftp_fput() stream'),
            $i64
        );
        $mode = self::modeArg($context, $args, $argc, 3, 'ftp_fput');
        $offset = self::offsetArg($context, $args, $argc, 4, 'ftp_fput');
        $saved = BasicBlockHelper::tryGetInsertBlock($context);
        StringFtpTransfer::ensureLinked($context);
        if (null !== $saved) {
            BasicBlockHelper::restoreInsertBlock($context, $saved);
        }
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_ftp_fput'),
            $handle,
            $remote,
            $stream,
            $mode,
            $offset
        );

        return self::boolFromI1($context, $ok);
    }

    /** @param list<JITVariable> $args */
    private static function modeArg(
        Context $context,
        array $args,
        int $argc,
        int $index,
        string $fn
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        if ($argc > $index) {
            return $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[$index], $fn.'() mode'),
                $i64
            );
        }

        // FTP_BINARY = 2 (php-src default).
        return $i64->constInt(2, false);
    }

    /** @param list<JITVariable> $args */
    private static function offsetArg(
        Context $context,
        array $args,
        int $argc,
        int $index,
        string $fn
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        if ($argc > $index) {
            return $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[$index], $fn.'() offset'),
                $i64
            );
        }

        return $i64->constInt(0, false);
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
