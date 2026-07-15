<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::createElement($name, $value) via DomCreateElementJitHelper (#18938). */
final class DomCreateElementRuntime
{
    public const ABI_NAME = '__phpc_dom_create_element';

    private const HELPER_PATH = '/ext/dom/DomCreateElementJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createElementArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureCreateElementBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_create_element_bridge',
            [$objPtr, $strPtr, $strPtr],
            $objPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#18938'
        );
    }
}
