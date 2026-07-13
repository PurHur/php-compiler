<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;

/** JIT/AOT link for DOMDocument::getElementsByTagName() via DomGetElementsByTagNameJitHelper (#18461). */
final class DomGetElementsByTagNameRuntime
{
    public const ABI_NAME = '__phpc_dom_get_elements_by_tag_name';

    private const HELPER_PATH = '/ext/dom/DomGetElementsByTagNameJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomGetElementsByTagNameJitHelper::getElementsByTagNameArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureGetElementsByTagNameBridge($context);

            return;
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, 'dom_get_elements_by_tag_name_bridge')) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#18461');

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HELPER, '#18461');
        $ft = $context->context->functionType($valuePtr, false, $objPtr, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, 'dom_get_elements_by_tag_name_bridge');
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $bridgeArgs = [
            JitNestedHelperCoerce::coerceArgForHelper($context, $vmCtx, $helperFn->getParam(0)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $fn->getParam(0), $helperFn->getParam(1)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $fn->getParam(1), $helperFn->getParam(2)->typeOf()),
        ];
        $foundObj = $context->builder->call($helperFn, ...$bridgeArgs);
        $foundObj = JitNestedHelperCoerce::coerceBridgeResult($context, $foundObj, $objPtr);
        $context->builder->returnValue(JitValueBox::nullableObjectToValuePtr($context, $foundObj));
        $context->registerFunction(self::ABI_NAME, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
