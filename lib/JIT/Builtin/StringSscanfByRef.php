<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_sscanf / __compiler_sscanf_ex via SscanfJitHelper PHP (#12467, #25691).
 *
 * Helper compile: bundled {@see JitVmHelperLink::ensureCompiledBundle} (VmSscanf → SscanfJitHelper)
 * in one NestedJIT scope (peer StringSscanfArray #25653 / StringPack #22842).
 * php-src: ext/standard/sscanf.c — by-reference assignment branch
 */
final class StringSscanfByRef
{
    private const HELPER_PATH = '/ext/standard/SscanfJitHelper.php';

    private const VM_SSCANF_PATH = '/ext/standard/VmSscanf.php';

    /**
     * Ordered NestedJIT sources — VmSscanf before SscanfJitHelper (#25691).
     *
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        self::VM_SSCANF_PATH,
        self::HELPER_PATH,
    ];

    private const PARSE_ASSIGN_HELPER = 'PHPCompiler\\ext\\standard\\SscanfJitHelper::parseAssignMeta';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_ASSIGN_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_sscanf',
        '__compiler_sscanf_ex',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_sscanf');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureRuntimeHelpers($context);
        SscanfAssignApply::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementSscanfBridge($context, '__compiler_sscanf', false);
        self::implementSscanfBridge($context, '__compiler_sscanf_ex', true);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementSscanfBridge(Context $context, string $abiName, bool $trackConsumed): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtrPtr = $context->getTypeFromString('__value__**');
        $params = [$strPtr, $strPtr, $i64, $valuePtrPtr];
        if ($trackConsumed) {
            $params[] = $sizeT->pointerType(0);
        }
        $ft = $context->context->functionType($i64, false, ...$params);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('sscanf_byref_entry');
        $nullRet = $fn->appendBasicBlock('sscanf_byref_null');
        $work = $fn->appendBasicBlock('sscanf_byref_work');
        $context->builder->positionAtEnd($entry);

        $str = $fn->getParam(0);
        $fmt = $fn->getParam(1);
        $outCount = $fn->getParam(2);
        $outPtrs = $fn->getParam(3);
        $consumedOut = $trackConsumed ? $fn->getParam(4) : null;
        $zero64 = $i64->constInt(0, false);

        $nullStr = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $str, $strPtr->constNull()),
            $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull())
        );
        $context->builder->branchIf($nullStr, $nullRet, $work);

        $context->builder->positionAtEnd($nullRet);
        if ($trackConsumed && null !== $consumedOut) {
            $context->builder->store($sizeT->constInt(0, false), $consumedOut);
        }
        $context->builder->returnValue($zero64);

        $context->builder->positionAtEnd($work);
        $meta = $context->builder->call(
            self::helperFunction($context, self::PARSE_ASSIGN_HELPER),
            $str,
            $fmt,
            $outCount
        );
        $consumedSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $assigned = $context->builder->call(
            $context->lookupFunction('phpc_sscanf_apply_assign_blob'),
            $meta,
            $outPtrs,
            $consumedSlot
        );
        if ($trackConsumed && null !== $consumedOut) {
            $context->builder->store($context->builder->load($consumedSlot), $consumedOut);
        }
        $context->builder->returnValue($assigned);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25691');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#25691'
        );
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        \PHPCompiler\JIT\LibcExtern::register($context);
        \PHPCompiler\JIT\LibcExtern::ensureMemcpyDecl($context);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after StringSscanfByRef bridge (#12467)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
