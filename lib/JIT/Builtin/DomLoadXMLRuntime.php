<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::loadXML() via DomLoadXMLJitHelper (#18268). */
final class DomLoadXMLRuntime
{
    public const ABI_NAME = '__phpc_dom_load_xml';

    private const HELPER_PATH = '/ext/dom/DomLoadXMLJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomLoadXMLJitHelper::loadXMLArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureLoadXMLBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_load_xml_bridge',
            [$objPtr, $strPtr],
            $i1,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18268'
        );
    }
}
