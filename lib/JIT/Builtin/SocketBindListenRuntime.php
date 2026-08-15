<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_bind/listen/accept/create_listen via SocketCreateJitHelper (#31241/#31242).
 *
 * Uses the create/pair NestedJIT unit so owned fds resolve under thin AOT.
 *
 * php-src: ext/sockets/sockets.c
 */
final class SocketBindListenRuntime
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
        '__compiler_socket_bind',
        '__compiler_socket_listen',
        '__compiler_socket_accept',
        '__compiler_socket_domain_for_handle_create',
        '__compiler_socket_create_listen_fd',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_bind');
        $accept = $context->module->getNamedFunction('__compiler_socket_accept');
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && null !== $accept && $accept->countBasicBlocks() > 0) {
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
        self::implementBindBridge($context);
        self::implementListenBridge($context);
        self::implementAcceptBridge($context);
        self::implementDomainBridge($context);
        self::implementCreateListenBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementBindBridge(Context $context): void
    {
        self::implementI64StrI64Bridge(
            $context,
            '__compiler_socket_bind',
            'socket_bind_entry',
            self::H.'::bindArgv'
        );
    }

    private static function implementListenBridge(Context $context): void
    {
        self::implementI64I64Bridge(
            $context,
            '__compiler_socket_listen',
            'socket_listen_entry',
            self::H.'::listenArgv'
        );
    }

    private static function implementAcceptBridge(Context $context): void
    {
        self::implementI64Bridge(
            $context,
            '__compiler_socket_accept',
            'socket_accept_entry',
            self::H.'::acceptArgv'
        );
    }

    private static function implementDomainBridge(Context $context): void
    {
        self::implementI64Bridge(
            $context,
            '__compiler_socket_domain_for_handle_create',
            'socket_domain_create_entry',
            self::H.'::domainForHandleArgv'
        );
    }

    private static function implementCreateListenBridge(Context $context): void
    {
        self::implementI64I64Bridge(
            $context,
            '__compiler_socket_create_listen_fd',
            'socket_create_listen_entry',
            self::H.'::createListenFdArgv'
        );
    }

    private static function implementI64Bridge(
        Context $context,
        string $abiName,
        string $entryName,
        string $logical
    ): void {
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
                $context->context->functionType($i64, false, $i64)
            );
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $logical),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementI64I64Bridge(
        Context $context,
        string $abiName,
        string $entryName,
        string $logical
    ): void {
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
                $context->context->functionType($i64, false, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $logical),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementI64StrI64Bridge(
        Context $context,
        string $abiName,
        string $entryName,
        string $logical
    ): void {
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
                $context->context->functionType($i64, false, $i64, $strPtr, $i64)
            );
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $logical),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31242');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31242'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SocketBindListenRuntime link (#31242)');
            }
            $context->registerFunction($name, $fn);
        }
        // Accept/create_listen also need create register ABI.
        $reg = $context->module->getNamedFunction('__compiler_socket_create_register');
        if (null !== $reg) {
            $context->registerFunction('__compiler_socket_create_register', $reg);
        }
    }
}
