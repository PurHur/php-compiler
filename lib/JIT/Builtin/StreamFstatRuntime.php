<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamIoKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_fstat via FstatJitHelper PHP (#10460, #24586).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer MathModf #22519).
 * Thin standalone AOT: {@see JitStreamIoKernel::implementFstatForce} replaces NestedJIT —
 * VmFs cannot resolve StreamIo FILE* ids (#33359, peer flock #33122).
 * SSOT: {@see \PHPCompiler\ext\standard\VmStreamFstat}, {@see \PHPCompiler\ext\standard\VmFs}
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(fstat)
 */
final class StreamFstatRuntime
{
    private const HELPER_PATH = '/ext/standard/FstatJitHelper.php';

    private const FSTAT_HELPER = 'PHPCompiler\\ext\\standard\\FstatJitHelper::fstatArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FSTAT_HELPER,
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
                JitStreamIoKernel::implementFstatForce($context);
            }

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementFstatBridge($context);
        self::registerLinkedRuntime($context);
        if ($context->isThinStandaloneAotMain()) {
            JitStreamIoKernel::implementFstatForce($context);
        }
        $context->builder->clearInsertionPosition();
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
        $body = $fn->appendBasicBlock('fstat_bridge_body');
        $context->builder->positionAtEnd($entry);
        $context->builder->branch($body);

        $context->builder->positionAtEnd($body);
        $htRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::FSTAT_HELPER),
            [$fn->getParam(0)]
        );
        $htNull = JitNestedHelperCoerce::isHelperResultNull($context, $htRaw);
        $retBb = $fn->appendBasicBlock('fstat_bridge_ret');
        $context->builder->branchIf($htNull, $fail, $retBb);

        $context->builder->positionAtEnd($retBb);
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
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
