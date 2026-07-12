<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMElement::$textContent via DomElementTextContentJitHelper (#17954). */
final class DomElementTextContentRuntime
{
    public const ABI_NAME = '__phpc_dom_element_text_content';

    private const HELPER_PATH = '/ext/dom/DomElementTextContentJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomElementTextContentJitHelper::textContentArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureElementTextContentBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_element_text_content_bridge',
            [$objPtr],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17954'
        );
    }
}
