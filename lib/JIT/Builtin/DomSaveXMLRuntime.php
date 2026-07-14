<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin\DomDocumentMethodUserScriptLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::saveXML() via DomSaveXMLJitHelper (#18268). */
final class DomSaveXMLRuntime
{
    public const ABI_NAME = '__phpc_dom_save_xml';

    private const HELPER_PATH = '/ext/dom/DomSaveXMLJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomSaveXMLJitHelper::saveXMLArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureSaveXMLBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_save_xml_bridge',
            [$objPtr, $valuePtr],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18268'
        );
    }
}
