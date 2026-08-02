<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_version_compare via VersionCompareJitHelper PHP (#9813, #26866).
 *
 * Custom bridge (peer MathBaseConvertRuntime #26884): pass `__string__*` straight through —
 * the generic helper-link coerce path left NestedJIT seeing empty / wrong compare results
 * under thin AOT so unequal versions compared as 0 (#26866).
 *
 * SSOT algorithm: {@see \PHPCompiler\ext\standard\VersionCompareJitHelper} (NestedJIT-safe;
 * do not call VmInfo from the helper).
 * php-src: ext/standard/versioning.c — php_version_compare
 */
final class StringVersionCompare
{
    private const ABI = '__compiler_version_compare';

    private const HELPER_PATH = '/ext/standard/VersionCompareJitHelper.php';

    private const COMPARE_HELPER = 'PHPCompiler\\ext\\standard\\VersionCompareJitHelper::compare';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::COMPARE_HELPER,
    ];

    private const BRIDGE_ENTRY = 'version_compare_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26866'
        );

        $fn = self::declareAbi($context, $probe);
        if (!JitVmHelperLink::hasNamedBridgeEntry($fn, self::BRIDGE_ENTRY)) {
            self::emitBridge($context, $fn);
        }
        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function declareAbi(Context $context, ?LlvmFunction $probe): LlvmFunction
    {
        if (null !== $probe) {
            return $probe;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

        return $context->module->addFunction(
            self::ABI,
            $context->context->functionType($i64, false, $strPtr, $strPtr)
        );
    }

    private static function emitBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock(self::BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);

        $helper = JitVmHelperLink::lookupCompiled($context, self::COMPARE_HELPER, '#26866');
        // Pass `__string__*` straight through — do not coerceArgForHelper (#26866 / #26884).
        $raw = $context->builder->call(
            $helper,
            $fn->getParam(0),
            $fn->getParam(1)
        );
        $i64 = $context->getTypeFromString('int64');
        // Helper returns 0/1/2 (lt/eq/gt) — NestedJIT corrupts negative ABI returns after
        // string ops under thin AOT (#26866). Decode to php-src -1/0/1.
        $encoded = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
        $ret = $context->builder->sub($encoded, $i64->constInt(1, false));
        $context->builder->returnValue($ret);
    }
}
