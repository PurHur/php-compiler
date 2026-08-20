<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMElement::insertAdjacent* via DomInsertAdjacentJitHelper. */
final class DomInsertAdjacentRuntime
{
    public const ABI_ELEMENT = '__phpc_dom_insert_adjacent_element';

    public const ABI_TEXT = '__phpc_dom_insert_adjacent_text';

    private const HELPER_PATH = '/ext/dom/DomInsertAdjacentJitHelper.php';

    private const HELPER_ELEMENT = 'PHPCompiler\\ext\\dom\\DomInsertAdjacentJitHelper::insertAdjacentElementArgv';

    private const HELPER_TEXT = 'PHPCompiler\\ext\\dom\\DomInsertAdjacentJitHelper::insertAdjacentTextArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_ELEMENT,
        self::HELPER_TEXT,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureInsertAdjacentElementBridge($context);
            JitDomDocumentMethodKernel::ensureInsertAdjacentTextBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_ELEMENT,
            'dom_insert_adjacent_element_bridge',
            [$objPtr, $strPtr, $objPtr],
            $context->context->voidType(),
            self::HELPER_ELEMENT,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#insert-adjacent'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_TEXT,
            'dom_insert_adjacent_text_bridge',
            [$objPtr, $strPtr, $strPtr],
            $context->context->voidType(),
            self::HELPER_TEXT,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#insert-adjacent'
        );
    }
}
