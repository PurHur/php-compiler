<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;

/** JIT/AOT link for DOMDocument::load() via DomLoadJitHelper (#18897). */
final class DomLoadRuntime
{
    public const ABI_NAME = '__phpc_dom_load';

    private const HELPER_PATH = '/ext/dom/DomLoadJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomLoadJitHelper::loadArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureLoadBridge($context);

            return;
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'dom_load_bridge')) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#18897');

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HELPER, '#18897');
        $ft = $context->context->functionType($i1, false, $objPtr, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, 'dom_load_bridge');
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $bridgeArgs = [
            JitNestedHelperCoerce::coerceArgForHelper($context, $vmCtx, $helperFn->getParam(0)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $fn->getParam(0), $helperFn->getParam(1)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $fn->getParam(1), $helperFn->getParam(2)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $fn->getParam(2), $helperFn->getParam(3)->typeOf()),
        ];
        $result = $context->builder->call($helperFn, ...$bridgeArgs);
        $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $i1);
        $context->builder->returnValue($ret);
        $context->registerFunction(self::ABI_NAME, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
