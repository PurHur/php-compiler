<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamIoKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_fstat via FstatJitHelper PHP (#10460, #24586, #33359).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MathModf #22519).
 * Thin AOT: NestedJIT VmFs cannot see {@see JitStreamIoKernel} FILE* handles — force
 * resolve→fileno→{@see FstatJitHelper::fstatFdArgv} (peer ftell/fflush #33122 / #33354).
 * SSOT: {@see \PHPCompiler\ext\standard\VmStreamFstat}, {@see \PHPCompiler\ext\standard\VmFs}
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(fstat)
 */
final class StreamFstatRuntime
{
    private const HELPER_PATH = '/ext/standard/FstatJitHelper.php';

    private const FSTAT_HELPER = 'PHPCompiler\\ext\\standard\\FstatJitHelper::fstatArgv';

    private const FSTAT_FD_HELPER = 'PHPCompiler\\ext\\standard\\FstatJitHelper::fstatFdArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FSTAT_HELPER,
        self::FSTAT_FD_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_fstat',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fstat');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            if ($context->isThinStandaloneAotMain()) {
                self::forceLibcFstat($context);
            }

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureJitHelperCompiled($context);
        self::implementFstatBridge($context);
        self::registerLinkedRuntime($context);
        if ($context->isThinStandaloneAotMain()) {
            self::forceLibcFstat($context);
        }
        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Thin AOT: replace NestedJIT VmFs bridge with libc FILE* → fileno → fstatFd (#33359).
     *
     * Call after StreamIo/StreamRead libc forces so resolve/fileno decls exist.
     */
    public static function forceLibcFstat(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_fstat');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach ($probe->getBasicBlocks() as $bb) {
                if ('fstat_libc_entry' === $bb->getName()) {
                    $context->registerFunction('__compiler_fstat', $probe);

                    return;
                }
                break;
            }
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $context->builder->clearInsertionPosition();

        // Ensure resolve + fileno via an existing libc stream force.
        JitStreamIoKernel::implementFtellForce($context);
        LibcExtern::ensureResolveStreamDecl($context);
        self::ensureFilenoDecl($context);
        self::ensureJitHelperCompiled($context);

        $probe = $context->module->getNamedFunction('__compiler_fstat');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            foreach (array_reverse($probe->getBasicBlocks()) as $block) {
                $block->delete();
            }
            $fn = $probe;
        } else {
            $i64 = $context->getTypeFromString('int64');
            $htPtr = $context->getTypeFromString('__hashtable__*');
            $fn = $context->module->addFunction(
                '__compiler_fstat',
                $context->context->functionType($htPtr, false, $i64)
            );
        }

        self::emitLibcFstat($context, $fn);
        $context->registerFunction('__compiler_fstat', $fn);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitLibcFstat(Context $context, LlvmFunction $fn): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $nullPtr = $i8p->constNull();
        $nullHt = $htPtr->constNull();

        $entry = $fn->appendBasicBlock('fstat_libc_entry');
        $fail = $fn->appendBasicBlock('fstat_libc_fail');
        $filenoBb = $fn->appendBasicBlock('fstat_libc_fileno');
        $ok = $fn->appendBasicBlock('fstat_libc_ok');
        $context->builder->positionAtEnd($entry);

        $fp = $context->builder->call(
            $context->lookupFunction('__phpc_resolve_stream'),
            $fn->getParam(0)
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr),
            $fail,
            $filenoBb
        );

        $context->builder->positionAtEnd($filenoBb);
        $fd = $context->builder->call($context->lookupFunction('fileno'), $fp);
        $fdBad = $context->builder->icmp(Builder::INT_SLT, $fd, $i32->constInt(0, true));
        $context->builder->branchIf($fdBad, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $fd64 = $context->builder->sext($fd, $i64);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::FSTAT_FD_HELPER),
            [$fd64]
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $retBb = $fn->appendBasicBlock('fstat_libc_ret');
        $context->builder->branchIf($isNull, $fail, $retBb);

        $context->builder->positionAtEnd($retBb);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $raw);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($nullHt);
    }

    private static function ensureFilenoDecl(Context $context): void
    {
        try {
            $context->lookupFunction('fileno');

            return;
        } catch (\Throwable) {
        }
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $fn = $context->module->getNamedFunction('fileno');
        if (null === $fn) {
            $fn = $context->module->addFunction(
                'fileno',
                $context->context->functionType($i32, false, $i8p)
            );
        }
        $context->registerFunction('fileno', $fn);
    }

    private static function implementFstatBridge(Context $context): void
    {
        $abiName = '__compiler_fstat';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ft = $context->context->functionType($htPtr, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('fstat_bridge_entry');
        $fail = $fn->appendBasicBlock('fstat_bridge_fail');
        $ok = $fn->appendBasicBlock('fstat_bridge_ok');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::FSTAT_HELPER),
            [$fn->getParam(0)]
        );
        $isNull = JitNestedHelperCoerce::isHelperResultNull($context, $raw);
        $context->builder->branchIf($isNull, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $raw);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($htPtr->constNull());
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#24586');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#24586'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StreamFstatRuntime bridge (#10460)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
