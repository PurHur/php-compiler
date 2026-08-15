<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_send()/socket_recv() via SocketCreateJitHelper (#31294).
 *
 * Same NestedJIT unit as create/pair so owned fds resolve under thin AOT.
 * Recv buffer is stashed (NestedJIT cannot return string+int together); LLVM writes by-ref.
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_send|socket_recv)
 */
final class SocketSendRecvRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketCreateJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sockets\\SocketCreateJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::createFdArgv',
        self::H.'::registerOwnedArgv',
        self::H.'::fdForHandleArgv',
        self::H.'::writeArgv',
        self::H.'::readArgv',
        self::H.'::readFailedArgv',
        self::H.'::markReadFailedArgv',
        self::H.'::clearReadFailedArgv',
        self::H.'::bindArgv',
        self::H.'::listenArgv',
        self::H.'::acceptArgv',
        self::H.'::domainForHandleArgv',
        self::H.'::createListenFdArgv',
        self::H.'::shutdownArgv',
        self::H.'::sendtoArgv',
        self::H.'::getsocknameOkArgv',
        self::H.'::getpeernameOkArgv',
        self::H.'::nameAddrArgv',
        self::H.'::namePortArgv',
        self::H.'::sendArgv',
        self::H.'::recvArgv',
        self::H.'::recvDataArgv',
        self::H.'::recvEofArgv',
        self::H.'::setOptionIntArgv',
        self::H.'::getOptionIntOkArgv',
        self::H.'::getOptionValueArgv',
        self::H.'::recvfromArgv',
        self::H.'::recvfromDataArgv',
        self::H.'::recvfromAddrArgv',
        self::H.'::recvfromPortArgv',
        self::H.'::selectResetArgv',
        self::H.'::selectAddArgv',
        self::H.'::selectRunArgv',
        self::H.'::selectTimeoutMsArgv',
        self::H.'::selectEntryFdArgv',
        self::H.'::selectEntryEvArgv',
        self::H.'::selectMarkReadyArgv',
        self::H.'::selectReadyCountArgv',
        self::H.'::selectReadyHandleArgv',
        self::H.'::selectReadyKindArgv',
        self::H.'::selectReadyKeyArgv',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_socket_send',
        '__compiler_socket_recv',
        '__compiler_socket_recv_data',
        '__compiler_socket_recv_eof',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_send');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        SocketCreateRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementSendBridge($context);
        self::implementRecvBridge($context);
        self::implementRecvDataBridge($context);
        self::implementRecvEofBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementSendBridge(Context $context): void
    {
        $abiName = '__compiler_socket_send';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $strPtr, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('socket_send_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::sendArgv'),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2), $fn->getParam(3)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRecvBridge(Context $context): void
    {
        $abiName = '__compiler_socket_recv';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('socket_recv_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::recvArgv'),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRecvDataBridge(Context $context): void
    {
        $abiName = '__compiler_socket_recv_data';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false)
            );

        $entry = $fn->appendBasicBlock('socket_recv_data_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::recvDataArgv'),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRecvEofBridge(Context $context): void
    {
        $abiName = '__compiler_socket_recv_eof';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false)
            );

        $entry = $fn->appendBasicBlock('socket_recv_eof_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::recvEofArgv'),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31294');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31294'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SocketSendRecvRuntime link (#31294)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
