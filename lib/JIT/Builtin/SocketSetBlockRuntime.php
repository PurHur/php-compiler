<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for socket_set_block / socket_set_nonblock (#31285, re-#6289).
 *
 * Fd resolve: {@see \PHPCompiler\ext\sockets\SocketCreateJitHelper::fdForHandleArgv}
 * (create_pair NestedJIT maps + VmSocket). fcntl(2) is LLVM libc (peer write #27423).
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_set_block|set_nonblock)
 */
final class SocketSetBlockRuntime
{
    private const HELPER_PATH = '/ext/sockets/SocketCreateJitHelper.php';

    private const H = 'PHPCompiler\\ext\\sockets\\SocketCreateJitHelper';

    private const FD_HELPER = self::H.'::fdForHandleArgv';

    /** Linux x86_64 / glibc — match {@see \PHPCompiler\ext\sockets\VmSockets}. */
    private const F_GETFL = 3;

    private const F_SETFL = 4;

    private const O_NONBLOCK = 2048;

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FD_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_socket_set_block',
        '__compiler_socket_set_nonblock',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_socket_set_block');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'socket_set_block_bridge_entry')) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        // Pair/create NestedJIT unit owns fd maps — compile those helpers first (#27423).
        StringSocketPairIo::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::ensureFcntlLibc($context);
        self::implementSetBlockBridge($context, false);
        self::implementSetBlockBridge($context, true);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    private static function implementSetBlockBridge(Context $context, bool $nonblock): void
    {
        $abiName = $nonblock ? '__compiler_socket_set_nonblock' : '__compiler_socket_set_block';
        $entryName = $nonblock ? 'socket_set_nonblock_bridge_entry' : 'socket_set_block_bridge_entry';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryName)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i32, false, $i64)
            );

        $entry = $fn->appendBasicBlock($entryName);
        $failBb = $fn->appendBasicBlock($abiName.'_fail');
        $okBb = $fn->appendBasicBlock($abiName.'_ok');
        $context->builder->positionAtEnd($entry);

        $rawFd = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context),
            [$fn->getParam(0)]
        );
        $fdI64 = JitNestedHelperCoerce::coerceHelperScalarResult($context, $rawFd, $i64);
        $fdOk = $context->builder->icmp(Builder::INT_SGE, $fdI64, $i64->constInt(0, true));
        $context->builder->branchIf($fdOk, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($i32->constInt(0, false));

        $context->builder->positionAtEnd($okBb);
        $fdI32 = $context->builder->trunc($fdI64, $i32);
        $fcntl = $context->lookupFunction('fcntl');
        $flags = $context->builder->call(
            $fcntl,
            $fdI32,
            $i32->constInt(self::F_GETFL, false),
            $i32->constInt(0, false)
        );
        $getFail = $context->builder->icmp(Builder::INT_EQ, $flags, $i32->constInt(-1, true));
        $getFailBb = $fn->appendBasicBlock($abiName.'_getfl_fail');
        $setBb = $fn->appendBasicBlock($abiName.'_setfl');
        $context->builder->branchIf($getFail, $getFailBb, $setBb);

        $context->builder->positionAtEnd($getFailBb);
        $context->builder->returnValue($i32->constInt(0, false));

        $context->builder->positionAtEnd($setBb);
        $onb = $i32->constInt(self::O_NONBLOCK, false);
        $newFlags = $nonblock
            ? $context->builder->or($flags, $onb)
            : $context->builder->and($flags, $context->builder->not($onb));
        $rc = $context->builder->call(
            $fcntl,
            $fdI32,
            $i32->constInt(self::F_SETFL, false),
            $newFlags
        );
        $ok = $context->builder->icmp(Builder::INT_NE, $rc, $i32->constInt(-1, true));
        $context->builder->returnValue(
            $context->builder->select(
                $ok,
                $i32->constInt(1, false),
                $i32->constInt(0, false)
            )
        );

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function ensureFcntlLibc(Context $context): void
    {
        LibcExtern::register($context);
        $fcntl = $context->module->getNamedFunction('fcntl');
        if (null !== $fcntl) {
            $context->registerFunction('fcntl', $fcntl);

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $fn = $context->module->addFunction(
            'fcntl',
            $context->context->functionType($i32, false, $i32, $i32, $i32)
        );
        $context->registerFunction('fcntl', $fn);
    }

    private static function helperFunction(Context $context): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, self::FD_HELPER, '#31285');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#31285'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SocketSetBlockRuntime link (#31285)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
