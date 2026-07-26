<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMElement::$textContent via DomElementTextContentJitHelper (#17954, #23251). */
final class DomElementTextContentRuntime
{
    public const ABI_NAME = '__phpc_dom_element_text_content';

    public const ABI_WRITE_TEXT_CONTENT = '__phpc_dom_element_text_content_write';

    public const ABI_WRITE_NODE_VALUE = '__phpc_dom_element_node_value_write';

    private const HELPER_PATH = '/ext/dom/DomElementTextContentJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomElementTextContentJitHelper::textContentArgv';

    private const HELPER_WRITE_TEXT = 'PHPCompiler\\ext\\dom\\DomElementTextContentJitHelper::writeTextContentArgv';

    private const HELPER_WRITE_NODE_VALUE = 'PHPCompiler\\ext\\dom\\DomElementTextContentJitHelper::writeNodeValueArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
        self::HELPER_WRITE_TEXT,
        self::HELPER_WRITE_NODE_VALUE,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureElementTextContentBridge($context);

            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

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

    public static function ensureWriteLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureElementTextContentWriteBridge($context);
            JitDomDocumentMethodKernel::ensureElementNodeValueWriteBridge($context);

            return;
        }

        self::ensureWriteAbi(
            $context,
            self::ABI_WRITE_TEXT_CONTENT,
            'dom_element_text_content_write_bridge',
            self::HELPER_WRITE_TEXT
        );
        self::ensureWriteAbi(
            $context,
            self::ABI_WRITE_NODE_VALUE,
            'dom_element_node_value_write_bridge',
            self::HELPER_WRITE_NODE_VALUE
        );
    }

    private static function ensureWriteAbi(
        Context $context,
        string $abi,
        string $entry,
        string $helper
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abi, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->context->voidType();
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            $entry,
            [$objPtr, $strPtr],
            $void,
            $helper,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23251'
        );
    }
}
