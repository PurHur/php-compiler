<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;

/**
 * User-script standalone AOT bridge for __compiler_var_export (#19430, #17316, #16075).
 *
 * Nested VarExportJitHelper from user-main emit segfaults; link bridge during lowering
 * after VmActiveContextInit publishes sg_vm_context (#17391). Housed in ext/standard
 * (not lib/JIT/Builtin) — same kernel-move pattern as #19389 / #19399.
 * php-src: ext/standard/var.c — php_var_export_ex
 */
final class JitVarExportKernel
{
    private const HELPER_PATH = '/ext/standard/VarExportJitHelper.php';

    private const FORMAT_VALUE_HELPER = 'PHPCompiler\\ext\\standard\\VarExportJitHelper::formatValue';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_VALUE_HELPER,
    ];

    public static function implement(Context $context): void
    {
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $abiName = '__compiler_var_export';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'var_export_user_script_bridge')) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17316'
        );

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::FORMAT_VALUE_HELPER, '#17316');
        $ft = $context->context->functionType($strPtr, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, 'var_export_user_script_bridge');
        $context->builder->positionAtEnd($entry);
        $bridgeArgs = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam(0),
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        $result = $context->builder->call($helperFn, ...$bridgeArgs);
        $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $strPtr);
        $context->builder->returnValue($ret);
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
