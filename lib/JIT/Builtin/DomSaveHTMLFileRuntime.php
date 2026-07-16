<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::saveHTMLFile() via DomSaveHTMLFileJitHelper (#18268). */
final class DomSaveHTMLFileRuntime
{
    public const ABI_NAME = '__phpc_dom_save_html_file';

    private const HELPER_PATH = '/ext/dom/DomSaveHTMLFileJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomSaveHTMLFileJitHelper::saveHTMLFileArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSaveHTMLFileBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_save_html_file_bridge',
            [$objPtr, $strPtr],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18268'
        );
    }
}
