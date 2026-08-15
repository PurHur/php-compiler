<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;

/**
 * JIT/AOT link for socket_strerror/last_error/clear_error (#31270).
 *
 * - last_error / clear_error: NestedJIT PHP via SocketErrorJitHelper + VmSocket maps
 * - strerror: libc strerror(3) + host-lookup band as LLVM string constants
 *   (FFI unavailable under NestedJIT thin AOT — peer SocketPairIo libc bridges)
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_strerror|last_error|clear_error)
 */
final class SocketErrorRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketErrorJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sockets\\SocketErrorJitHelper';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::H.'::lastErrorForHandle',
        self::H.'::clearErrorForHandle',
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_socket_strerror',
        '__compiler_socket_last_error',
        '__compiler_socket_clear_error',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_strerror');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'socket_strerror_bridge_entry')) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementStrerrorBridge($context);
        self::implementLastErrorBridge($context);
        self::implementClearErrorBridge($context);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function implementStrerrorBridge(Context $context): void
    {
        $abiName = '__compiler_socket_strerror';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'socket_strerror_bridge_entry')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        LibcExtern::register($context);
        self::ensureStrerrorLibc($context);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');

        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $i64)
            );

        $entry = $fn->appendBasicBlock('socket_strerror_bridge_entry');
        $hostBb = $fn->appendBasicBlock('socket_strerror_host');
        $libcBb = $fn->appendBasicBlock('socket_strerror_libc');
        $context->builder->positionAtEnd($entry);

        $errno = $fn->getParam(0);
        $isHost = $context->builder->icmp(
            Builder::INT_SLT,
            $errno,
            $i64->constInt(-10000, true)
        );
        $context->builder->branchIf($isHost, $hostBb, $libcBb);

        // php-src sockets_strerror() host-lookup band (error < -10000) — LLVM string
        // constants (NestedJIT string helper segfaulted under AotTest; #31270).
        $context->builder->positionAtEnd($hostBb);
        $msgs = [
            -10001 => 'Unknown host',
            -10002 => 'Host name lookup failure',
            -10003 => 'Unknown server error',
            -10004 => 'No address associated with name',
            -10005 => 'Unknown resolver error',
        ];
        $next = $hostBb;
        $i = 0;
        foreach ($msgs as $code => $text) {
            $matchBb = $fn->appendBasicBlock('socket_strerror_host_'.$i);
            $contBb = $fn->appendBasicBlock('socket_strerror_host_cont_'.$i);
            $context->builder->positionAtEnd($next);
            $isMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $errno,
                $i64->constInt($code, true)
            );
            $context->builder->branchIf($isMatch, $matchBb, $contBb);
            $context->builder->positionAtEnd($matchBb);
            $context->builder->returnValue(
                $context->builder->load($context->constantStringFromString($text))
            );
            $next = $contBb;
            ++$i;
        }
        $context->builder->positionAtEnd($next);
        $context->builder->returnValue(
            $context->builder->load($context->constantStringFromString('Host lookup error'))
        );

        $context->builder->positionAtEnd($libcBb);
        $errnoI32 = $context->builder->trunc($errno, $i32);
        $cstr = $context->builder->call(
            $context->lookupFunction('strerror'),
            $errnoI32
        );
        $nullCstr = $context->builder->pointerCast(
            $context->constantFromString(''),
            $i8p
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $cstr, $nullCstr);
        $fallbackBb = $fn->appendBasicBlock('socket_strerror_fallback');
        $okBb = $fn->appendBasicBlock('socket_strerror_ok');
        $context->builder->branchIf($isNull, $fallbackBb, $okBb);

        $context->builder->positionAtEnd($fallbackBb);
        $fallback = $context->builder->load(
            $context->constantStringFromString('Unknown error')
        );
        $context->builder->returnValue($fallback);

        $context->builder->positionAtEnd($okBb);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
        $context->builder->returnValue($str);

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementLastErrorBridge(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_socket_last_error',
            'socket_last_error_bridge_entry',
            [$i64],
            $i64,
            self::H.'::lastErrorForHandle',
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31270'
        );
    }

    private static function implementClearErrorBridge(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_socket_clear_error',
            'socket_clear_error_bridge_entry',
            [$i64],
            $voidTy,
            self::H.'::clearErrorForHandle',
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31270'
        );
    }

    private static function ensureStrerrorLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        try {
            $context->lookupFunction('strerror');
        } catch (\Throwable) {
            $fn = $context->module->addFunction(
                'strerror',
                $context->context->functionType($i8p, false, $i32)
            );
            $context->registerFunction('strerror', $fn);
        }
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31270'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SocketErrorRuntime link (#31270)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
