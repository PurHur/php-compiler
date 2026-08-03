<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\JIT\ClassConstFetchHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for late-static scope via LateStaticJitHelper PHP (#10247, #27416).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ObStatusRuntime #27321 / StringDeployPath #27037).
 * Replaces inline LLVM select in LateStaticBindingHelper. Global storage stays thin ABI.
 * php-src: Zend/zend_execute.c — get_called_scope()
 * SSOT: {@see \PHPCompiler\VM\LateStaticBinding}, {@see \PHPCompiler\VM\LateStaticJitHelper}
 */
final class LateStaticBindingRuntime
{
    private const HELPER_PATH = '/lib/VM/LateStaticJitHelper.php';

    private const EFFECTIVE_ID_HELPER = 'PHPCompiler\\VM\\LateStaticJitHelper::effectiveCalledClassId';

    private const ABI_EFFECTIVE_ID = '__late_static__effectiveCalledClassId';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EFFECTIVE_ID_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_EFFECTIVE_ID);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_EFFECTIVE_ID, $probe);

            return;
        }

        LateStaticBindingGlobals::ensureGlobal($context);
        self::ensureJitHelperCompiled($context);
        self::implementEffectiveIdBridge($context);
        $context->builder->clearInsertionPosition();
    }

    public static function emitStoreClassId(Context $context, Value $classId): void
    {
        LateStaticBindingGlobals::emitStore($context, $classId);
    }

    public static function emitLoadClassId(Context $context): Value
    {
        return LateStaticBindingGlobals::emitLoad($context);
    }

    /**
     * @return Value int64 class id (0 = use declaring scope fallback)
     */
    public static function emitEffectiveLateStaticClassId(
        Object_ $objectType,
        Block $block
    ): Value {
        $context = $objectType->jitContext();
        self::ensureLinked($context);
        $runtimeId = self::emitLoadClassId($context);
        $scopeClass = ClassConstFetchHelper::resolveJitScopeClassNameForBlock($objectType, $block);
        if (null === $scopeClass) {
            return $runtimeId;
        }
        $fallbackId = $objectType->lookup($scopeClass);

        return self::callEffectiveCalledClassId($context, $runtimeId, $fallbackId);
    }

    public static function callEffectiveCalledClassId(
        Context $context,
        Value $runtimeId,
        int $declaringScopeClassId
    ): Value {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_EFFECTIVE_ID);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $fn,
            $runtimeId,
            $context->constantFromInteger($declaringScopeClassId, 'int64')
        );
    }

    private static function implementEffectiveIdBridge(Context $context): void
    {
        $abiName = self::ABI_EFFECTIVE_ID;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('lsb_effective_id_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::EFFECTIVE_ID_HELPER),
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#27416');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27416'
        );
    }
}
