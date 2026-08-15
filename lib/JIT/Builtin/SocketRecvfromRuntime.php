<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_recvfrom() via SocketCreateJitHelper (#31332).
 *
 * Same NestedJIT unit as create/pair/sendto so owned fds resolve under thin AOT.
 * Data/addr/port are stashed (NestedJIT cannot return string+int together); LLVM writes by-ref.
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_recvfrom)
 */
final class SocketRecvfromRuntime
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
        '__compiler_socket_recvfrom',
        '__compiler_socket_recvfrom_data',
        '__compiler_socket_recvfrom_addr',
        '__compiler_socket_recvfrom_port',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_recvfrom');
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
        self::implementRecvfromBridge($context);
        self::implementStringBridge($context, '__compiler_socket_recvfrom_data', self::H.'::recvfromDataArgv', 'socket_recvfrom_data_entry');
        self::implementStringBridge($context, '__compiler_socket_recvfrom_addr', self::H.'::recvfromAddrArgv', 'socket_recvfrom_addr_entry');
        self::implementPortBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementRecvfromBridge(Context $context): void
    {
        $abiName = '__compiler_socket_recvfrom';
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

        $entry = $fn->appendBasicBlock('socket_recvfrom_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::recvfromArgv'),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementStringBridge(
        Context $context,
        string $abiName,
        string $helper,
        string $entryName
    ): void {
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

        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helper),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $strPtr)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementPortBridge(Context $context): void
    {
        $abiName = '__compiler_socket_recvfrom_port';
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

        $entry = $fn->appendBasicBlock('socket_recvfrom_port_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::recvfromPortArgv'),
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31332');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31332'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SocketRecvfromRuntime link (#31332)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
