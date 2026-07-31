<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_vfscanf via VfscanfJitHelper PHP (#12541, #25718).
 *
 * Helper compile: bundled {@see JitVmHelperLink::ensureCompiledBundle}
 * (VmSscanf → SscanfJitHelper → VmVfscanf → VfscanfJitHelper) in one NestedJIT scope
 * (peer StringSscanfByRef #25691 / StringSscanfArray #25653).
 * php-src: ext/standard/scanf.c — vfscanf stream branch
 */
final class StringVfscanf
{
    private const HELPER_PATH = '/ext/standard/VfscanfJitHelper.php';

    private const VM_VFSCANF_PATH = '/ext/standard/VmVfscanf.php';

    private const VM_SSCANF_PATH = '/ext/standard/VmSscanf.php';

    private const SSCANF_HELPER_PATH = '/ext/standard/SscanfJitHelper.php';

    /**
     * Ordered NestedJIT sources — sscanf chain before vfscanf (#25718).
     *
     * @var list<string>
     */
    private const HELPER_BUNDLE = [
        self::VM_SSCANF_PATH,
        self::SSCANF_HELPER_PATH,
        self::VM_VFSCANF_PATH,
        self::HELPER_PATH,
    ];

    private const PARSE_ASSIGN_HELPER = 'PHPCompiler\\ext\\standard\\VfscanfJitHelper::parseAssignMeta';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::PARSE_ASSIGN_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_vfscanf');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        SscanfAssignApply::ensureLinked($context);
        self::ensureJitHelperCompiled($context);
        self::implementVfscanfBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementVfscanfBridge(Context $context): void
    {
        $abiName = '__compiler_vfscanf';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtrPtr = $context->getTypeFromString('__value__**');
        $ft = $context->context->functionType(
            $i64,
            false,
            $i64,
            $strPtr,
            $i64,
            $valuePtrPtr
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('vfscanf_byref_entry');
        $fail = $fn->appendBasicBlock('vfscanf_byref_fail');
        $work = $fn->appendBasicBlock('vfscanf_byref_work');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $fmt = $fn->getParam(1);
        $outCount = $fn->getParam(2);
        $outPtrs = $fn->getParam(3);
        $minusOne = $i64->constInt(-1, false);

        $nullFmt = $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull());
        $context->builder->branchIf($nullFmt, $fail, $work);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($work);
        $meta = $context->builder->call(
            self::helperFunction($context, self::PARSE_ASSIGN_HELPER),
            $handle,
            $fmt,
            $outCount
        );
        $metaNull = $context->builder->icmp(Builder::INT_EQ, $meta, $strPtr->constNull());
        $apply = $fn->appendBasicBlock('vfscanf_byref_apply');
        $context->builder->branchIf($metaNull, $fail, $apply);

        $context->builder->positionAtEnd($apply);
        $consumedSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $assigned = $context->builder->call(
            $context->lookupFunction('phpc_sscanf_apply_assign_blob'),
            $meta,
            $outPtrs,
            $consumedSlot
        );
        $context->builder->returnValue($assigned);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#25718');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiledBundle(
            $context,
            self::HELPER_BUNDLE,
            self::COMPILED_HELPERS,
            '#25718'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_vfscanf');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException('__compiler_vfscanf missing after StringVfscanf bridge (#12541)');
        }
        $context->registerFunction('__compiler_vfscanf', $fn);
    }
}
