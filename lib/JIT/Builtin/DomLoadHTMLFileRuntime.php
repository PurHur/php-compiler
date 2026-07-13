<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;

/** JIT/AOT link for DOMDocument::loadHTMLFile() via DomLoadHTMLFileJitHelper (#18734). */
final class DomLoadHTMLFileRuntime
{
    public const ABI_NAME = '__phpc_dom_load_html_file';

    private const HELPER_PATH = '/ext/dom/DomLoadHTMLFileJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomLoadHTMLFileJitHelper::loadHTMLFileArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureLoadHTMLFileBridge($context);

            return;
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'dom_load_html_file_bridge')) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#18734');

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HELPER, '#18734');
        $ft = $context->context->functionType($i1, false, $objPtr, $strPtr, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, 'dom_load_html_file_bridge');
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
