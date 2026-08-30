<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for Dom\HTMLDocument::createFromFile() (leftover of #27300).
 *
 * Thin user-script AOT previously hit ExternalMethod → NULL.
 */
final class DomHtmlDocumentCreateFromFileRuntime
{
    public const ABI_NAME = '__phpc_dom_html_document_create_from_file';

    private const HELPER_PATH = '/ext/dom/DomHtmlDocumentCreateFromFileJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomHtmlDocumentCreateFromFileJitHelper::createFromFileArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureHtmlDocumentCreateFromFileBridge($context);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_html_document_create_from_file_bridge',
            [$strPtr, $i64],
            $objPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#27300'
        );
    }
}
