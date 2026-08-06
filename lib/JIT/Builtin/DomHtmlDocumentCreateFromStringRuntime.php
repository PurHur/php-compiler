<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for Dom\HTMLDocument::createFromString() (#27300).
 *
 * Thin user-script AOT previously hit ExternalMethod → NULL / abort (#27300).
 */
final class DomHtmlDocumentCreateFromStringRuntime
{
    public const ABI_NAME = '__phpc_dom_html_document_create_from_string';

    private const HELPER_PATH = '/ext/dom/DomHtmlDocumentCreateFromStringJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomHtmlDocumentCreateFromStringJitHelper::createFromStringArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureHtmlDocumentCreateFromStringBridge($context);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_html_document_create_from_string_bridge',
            [$strPtr, $i64],
            $objPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27300'
        );
    }
}
