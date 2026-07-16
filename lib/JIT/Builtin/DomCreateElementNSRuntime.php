<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::createElementNS() via DomCreateElementNSJitHelper (#18938). */
final class DomCreateElementNSRuntime
{
    public const ABI_NAME = '__phpc_dom_create_element_ns';

    private const HELPER_PATH = '/ext/dom/DomCreateElementNSJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomCreateElementNSJitHelper::createElementNSArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureCreateElementNSBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_create_element_ns_bridge',
            [$objPtr, $strPtr, $strPtr, $strPtr],
            $objPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18938'
        );
    }
}
