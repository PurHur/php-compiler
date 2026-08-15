<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Type;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_select() via SocketCreateJitHelper (#31355 / #6395).
 *
 * Same NestedJIT unit as create/pair so owned fds resolve under thin AOT.
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_select)
 */
final class SocketSelectRuntime
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
        '__compiler_socket_select_reset',
        '__compiler_socket_select_add',
        '__compiler_socket_select_run',
        '__compiler_socket_select_ready_count',
        '__compiler_socket_select_ready_handle',
        '__compiler_socket_select_ready_kind',
        '__compiler_socket_select_ready_key',
    ];

    public static function ensureLinked(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_socket_select_run');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        SocketCreateRuntime::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementI64InBridge($context, '__compiler_socket_select_reset', self::H.'::selectResetArgv', 'socket_select_reset_entry', []);
        self::implementAddBridge($context);
        self::implementRunBridge($context);
        self::implementI64InBridge($context, '__compiler_socket_select_ready_count', self::H.'::selectReadyCountArgv', 'socket_select_ready_count_entry', []);
        self::implementI64InBridge($context, '__compiler_socket_select_ready_handle', self::H.'::selectReadyHandleArgv', 'socket_select_ready_handle_entry', ['i64']);
        self::implementI64InBridge($context, '__compiler_socket_select_ready_kind', self::H.'::selectReadyKindArgv', 'socket_select_ready_kind_entry', ['i64']);
        self::implementI64InBridge($context, '__compiler_socket_select_ready_key', self::H.'::selectReadyKeyArgv', 'socket_select_ready_key_entry', ['i64']);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function implementAddBridge(Context $context): void
    {
        $abiName = '__compiler_socket_select_add';
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
        $entry = $fn->appendBasicBlock('socket_select_add_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::selectAddArgv'),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRunBridge(Context $context): void
    {
        $abiName = '__compiler_socket_select_run';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i16 = $context->getTypeFromString('int16');
        $i64 = $context->getTypeFromString('int64');
        self::ensureLibcPoll($context, $i32, $i16);

        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock('socket_select_run_entry');
        $emptyBb = $fn->appendBasicBlock('socket_select_run_empty');
        $pollBb = $fn->appendBasicBlock('socket_select_run_poll');
        $context->builder->positionAtEnd($entry);

        $nRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::selectRunArgv'),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $n = JitNestedHelperCoerce::coerceBridgeResult($context, $nRaw, $i64);
        $isEmpty = $context->builder->icmp(Builder::INT_SLE, $n, $i64->constInt(0, false));
        $context->builder->branchIf($isEmpty, $emptyBb, $pollBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($n);

        $context->builder->positionAtEnd($pollBb);
        $timeoutRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::selectTimeoutMsArgv'),
            []
        );
        $timeout = $context->builder->truncOrBitCast(
            JitNestedHelperCoerce::coerceBridgeResult($context, $timeoutRaw, $i64),
            $i32
        );
        $timeoutSlot = $context->builder->alloca($i32);
        $context->builder->store($timeout, $timeoutSlot);

        $pollfdTy = $context->context->structType(false, $i32, $i16, $i16);

        for ($i = 0; $i < 8; ++$i) {
            $iVal = $i64->constInt($i, false);
            $inRange = $context->builder->icmp(Builder::INT_SLT, $iVal, $n);
            $takeBb = $fn->appendBasicBlock('socket_select_run_i'.$i);
            $nextBb = $fn->appendBasicBlock('socket_select_run_n'.$i);
            $context->builder->branchIf($inRange, $takeBb, $nextBb);

            $context->builder->positionAtEnd($takeBb);
            $fdRaw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::H.'::selectEntryFdArgv'),
                [$iVal]
            );
            $fd64 = JitNestedHelperCoerce::coerceBridgeResult($context, $fdRaw, $i64);
            $evRaw = JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::H.'::selectEntryEvArgv'),
                [$iVal]
            );
            $ev64 = JitNestedHelperCoerce::coerceBridgeResult($context, $evRaw, $i64);
            $p = $context->builder->alloca($pollfdTy);
            $context->builder->store(
                $context->builder->truncOrBitCast($fd64, $i32),
                $context->builder->structGep($p, 0)
            );
            $context->builder->store(
                $context->builder->truncOrBitCast($ev64, $i16),
                $context->builder->structGep($p, 1)
            );
            $context->builder->store(
                $i16->constInt(0, false),
                $context->builder->structGep($p, 2)
            );
            $to = $context->builder->load($timeoutSlot);
            $rc = $context->builder->call(
                $context->lookupFunction('poll'),
                $p,
                $context->getTypeFromString('int64')->constInt(1, false), // nfds — use size_t-ish
                $to
            );
            // Subsequent fds: non-blocking
            $context->builder->store($i32->constInt(0, false), $timeoutSlot);
            $rc64 = $context->builder->sextOrBitCast($rc, $i64);
            $fail = $context->builder->icmp(Builder::INT_SLT, $rc64, $i64->constInt(0, false));
            $failBb = $fn->appendBasicBlock('socket_select_run_fail'.$i);
            $okBb = $fn->appendBasicBlock('socket_select_run_ok'.$i);
            $context->builder->branchIf($fail, $failBb, $okBb);

            $context->builder->positionAtEnd($failBb);
            $context->builder->returnValue($i64->constInt(-1, true));

            $context->builder->positionAtEnd($okBb);
            $rev = $context->builder->load($context->builder->structGep($p, 2));
            $rev64 = $context->builder->zextOrBitCast($rev, $i64);
            $has = $context->builder->icmp(
                Builder::INT_NE,
                $rev64,
                $i64->constInt(0, false)
            );
            $markBb = $fn->appendBasicBlock('socket_select_run_mark'.$i);
            $context->builder->branchIf($has, $markBb, $nextBb);

            $context->builder->positionAtEnd($markBb);
            // Match requested events or ERR/HUP (0x008|0x010).
            $req = $ev64;
            $errHup = $i64->constInt(0x008 | 0x010, false);
            $interesting = $context->builder->or(
                $context->builder->and($rev64, $req),
                $context->builder->and($rev64, $errHup)
            );
            $doMark = $context->builder->icmp(
                Builder::INT_NE,
                $interesting,
                $i64->constInt(0, false)
            );
            $doMarkBb = $fn->appendBasicBlock('socket_select_run_domark'.$i);
            $context->builder->branchIf($doMark, $doMarkBb, $nextBb);
            $context->builder->positionAtEnd($doMarkBb);
            JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::H.'::selectMarkReadyArgv'),
                [$iVal]
            );
            $context->builder->branch($nextBb);

            $context->builder->positionAtEnd($nextBb);
        }

        $countRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::H.'::selectReadyCountArgv'),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $countRaw, $i64)
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureLibcPoll(Context $context, Type $i32, Type $i16): void
    {
        $existing = $context->module->getNamedFunction('poll');
        if (null !== $existing) {
            $context->registerFunction('poll', $existing);

            return;
        }
        $pollfdTy = $context->context->structType(false, $i32, $i16, $i16);
        $nfds = $context->getTypeFromString('int64');
        $fn = $context->module->addFunction(
            'poll',
            $context->context->functionType($i32, false, $pollfdTy->pointerType(0), $nfds, $i32)
        );
        $context->registerFunction('poll', $fn);
    }

    /** @param list<string> $paramKinds */
    private static function implementI64InBridge(
        Context $context,
        string $abiName,
        string $helper,
        string $entryName,
        array $paramKinds
    ): void {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $params = [];
        foreach ($paramKinds as $_) {
            $params[] = $i64;
        }
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, ...$params)
            );
        $entry = $fn->appendBasicBlock($entryName);
        $context->builder->positionAtEnd($entry);
        $args = [];
        foreach ($paramKinds as $i => $_) {
            $args[] = $fn->getParam($i);
        }
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helper),
            $args
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#31355');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31355'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SocketSelectRuntime link (#31355)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
