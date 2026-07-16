<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::saveHTML() via DomSaveHTMLJitHelper (#18268). */
final class DomSaveHTMLRuntime
{
    public const ABI_NAME = '__phpc_dom_save_html';

    private const HELPER_PATH = '/ext/dom/DomSaveHTMLJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomSaveHTMLJitHelper::saveHTMLArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSaveHTMLBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_save_html_bridge',
            [$objPtr],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18268'
        );
    }
}
