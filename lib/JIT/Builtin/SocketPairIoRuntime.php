<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_create_pair / socket_write / socket_read (#27423).
 *
 * socketpair(2) is a direct libc LLVM call — NestedJIT FFI cannot read out-params (#27423).
 * Registration + write/read stay in {@see \PHPCompiler\ext\sockets\SocketCreateJitHelper}.
 */
final class SocketPairIoRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketCreateJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sockets\\SocketCreateJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::registerOwnedArgv',
        self::H.'::fdForHandleArgv',
        self::H.'::writeArgv',
        self::H.'::readArgv',
        self::H.'::readFailedArgv',
        self::H.'::markReadFailedArgv',
        self::H.'::clearReadFailedArgv',
        self::H.'::createFdArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_socket_create_pair',
        '__compiler_socket_write',
        '__compiler_socket_read',
        '__compiler_socket_read_failed',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_create_pair');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::ensureLibcSocketpair($context);
        self::implementCreatePairBridge($context);
        self::implementWriteBridge($context);
        self::implementReadBridge($context);
        self::implementReadFailedBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureLibcSocketpair(Context $context): void
    {
        $existing = $context->module->getNamedFunction('socketpair');
        if (null !== $existing) {
            $context->registerFunction('socketpair', $existing);

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i32Ptr = $i32->pointerType(0);
        $fn = $context->module->addFunction(
            'socketpair',
            $context->context->functionType($i32, false, $i32, $i32, $i32, $i32Ptr)
        );
        $context->registerFunction('socketpair', $fn);
    }

    private static function implementCreatePairBridge(Context $context): void
    {
        $abiName = '__compiler_socket_create_pair';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64, $i64, $i64, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('socket_create_pair_entry');
        $failBb = $fn->appendBasicBlock('socket_create_pair_fail');
        $okBb = $fn->appendBasicBlock('socket_create_pair_ok');
        $context->builder->positionAtEnd($entry);

        $sv = BasicBlockHelper::entryAlloca(
            $context,
            $i32->arrayType(2)
        );
        $zero = $i32->constInt(0, false);
        $sv0Ptr = $context->builder->gep($sv, $zero, $zero);
        $domain = $context->builder->trunc($fn->getParam(0), $i32);
        $type = $context->builder->trunc($fn->getParam(1), $i32);
        $protocol = $context->builder->trunc($fn->getParam(2), $i32);
        $rc = $context->builder->call(
            $context->lookupFunction('socketpair'),
            $domain,
            $type,
            $protocol,
            $sv0Ptr
        );
        $ok = $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false));
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i32->constInt(0, false));

        $context->builder->positionAtEnd($okBb);
        $fd0 = $context->builder->zext($context->builder->load($sv0Ptr), $i64);
        $sv1Ptr = $context->builder->gep($sv, $zero, $i32->constInt(1, false));
        $fd1 = $context->builder->zext($context->builder->load($sv1Ptr), $i64);
        $reg = self::helperFunction($context, self::H.'::registerOwnedArgv');
        $context->builder->call($reg, $fn->getParam(3), $fd0, $fn->getParam(0));
        $context->builder->call($reg, $fn->getParam(4), $fd1, $fn->getParam(0));
        $context->builder->returnValue($i32->constInt(1, false));

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementWriteBridge(Context $context): void
    {
        $abiName = '__compiler_socket_write';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        \PHPCompiler\JIT\LibcExtern::register($context);
        // Module-local write(2) after LibcExtern always-on drop (#31817).
        \PHPCompiler\JIT\LibcExtern::ensurePosixFd($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $strPtr, $i64)
            );

        $entry = $fn->appendBasicBlock('socket_write_entry');
        $failBb = $fn->appendBasicBlock('socket_write_fail');
        $okBb = $fn->appendBasicBlock('socket_write_ok');
        $context->builder->positionAtEnd($entry);

        // Resolve fd via NestedJIT; perform write(2) in LLVM — NestedJIT FFI send/write
        // returns 0 under thin AOT (#27423).
        $rawFd = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::fdForHandleArgv'),
            [$fn->getParam(0)]
        );
        $fdI64 = JitNestedHelperCoerce::coerceBridgeResult($context, $rawFd, $i64);
        $fdOk = $context->builder->icmp(Builder::INT_SGE, $fdI64, $i64->constInt(0, true));
        $context->builder->branchIf($fdOk, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i64->constInt(-1, true));

        $context->builder->positionAtEnd($okBb);
        $str = $fn->getParam(1);
        $reqLen = $fn->getParam(2);
        $stringMap = $context->structFieldMap['__string__'];
        $data = $context->builder->structGep($str, $stringMap['value']);
        $strLen = $context->builder->zext(
            $context->builder->load($context->builder->structGep($str, $stringMap['length'])),
            $i64
        );
        $useReq = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $reqLen, $i64->constInt(0, true)),
            $context->builder->icmp(Builder::INT_SLT, $reqLen, $strLen)
        );
        $len = $context->builder->select($useReq, $reqLen, $strLen);
        $n = $context->builder->call(
            $context->lookupFunction('write'),
            $context->builder->trunc($fdI64, $i32),
            $context->builder->pointerCast($data, $i8p),
            $len
        );
        $context->builder->returnValue($n);

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementReadBridge(Context $context): void
    {
        $abiName = '__compiler_socket_read';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        \PHPCompiler\JIT\LibcExtern::register($context);
        // Module-local read(2) after LibcExtern always-on drop (#31817).
        \PHPCompiler\JIT\LibcExtern::ensurePosixFd($context);
        // Module-local memcpy(3) after LibcExtern always-on drop (#31885).
        \PHPCompiler\JIT\LibcExtern::ensureMemcpyDecl($context);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('socket_read_entry');
        $failBb = $fn->appendBasicBlock('socket_read_fail');
        $okBb = $fn->appendBasicBlock('socket_read_ok');
        $context->builder->positionAtEnd($entry);

        $rawFd = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::fdForHandleArgv'),
            [$fn->getParam(0)]
        );
        $fdI64 = JitNestedHelperCoerce::coerceBridgeResult($context, $rawFd, $i64);
        $fdOk = $context->builder->icmp(Builder::INT_SGE, $fdI64, $i64->constInt(0, true));
        $context->builder->branchIf($fdOk, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->call(
            self::helperFunction($context, self::H.'::markReadFailedArgv')
        );
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->call(
            self::helperFunction($context, self::H.'::clearReadFailedArgv')
        );
        $length = $fn->getParam(1);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($length, $sizeT)
        );
        $n = $context->builder->call(
            $context->lookupFunction('read'),
            $context->builder->trunc($fdI64, $i32),
            $buf,
            $length
        );
        $neg = $context->builder->icmp(Builder::INT_SLT, $n, $i64->constInt(0, true));
        $errBb = $fn->appendBasicBlock('socket_read_err');
        $goodBb = $fn->appendBasicBlock('socket_read_good');
        $context->builder->branchIf($neg, $errBb, $goodBb);

        $context->builder->positionAtEnd($errBb);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->call(
            self::helperFunction($context, self::H.'::markReadFailedArgv')
        );
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($goodBb);
        $nSize = $context->builder->truncOrBitCast($n, $sizeT);
        $str = $context->builder->call($context->lookupFunction('__string__alloc'), $nSize);
        $stringMap = $context->structFieldMap['__string__'];
        $dst = $context->builder->structGep($str, $stringMap['value']);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($dst, $i8p),
            $buf,
            $nSize
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($str);

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementReadFailedBridge(Context $context): void
    {
        $abiName = '__compiler_socket_read_failed';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false)
            );

        $entry = $fn->appendBasicBlock('socket_read_failed_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::readFailedArgv'),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#27423');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27423'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SocketPairIoRuntime link (#27423)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
