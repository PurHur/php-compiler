<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::getElementById() via DomGetElementByIdJitHelper (#17954). */
final class DomGetElementByIdRuntime
{
    public const ABI_NAME = '__phpc_dom_get_element_by_id';

    private const HELPER_PATH = '/ext/dom/DomGetElementByIdJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomGetElementByIdJitHelper::getElementByIdArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureGetElementByIdBridge($context);

            return;
        }

        $entryBlock = 'dom_get_element_by_id_bridge';
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, '#17954');

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::HELPER, '#17954');
        $ft = $context->context->functionType($valuePtr, false, $objPtr, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i)->typeOf()
            );
        }
        $foundObj = $context->builder->call($helperFn, ...$args);
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
