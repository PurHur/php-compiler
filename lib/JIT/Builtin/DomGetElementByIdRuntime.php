<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::getElementById() via DomGetElementByIdJitHelper (#17954). */
final class DomGetElementByIdRuntime
{
    public const ABI_NAME = '__phpc_dom_get_element_by_id';

    private const HELPER_PATH = '/ext/dom/DomGetElementByIdJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomGetElementByIdJitHelper::getElementByIdArgv';

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
            DomDocumentMethodUserScriptLlvm::ensureGetElementByIdBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_get_element_by_id_bridge',
            [$objPtr, $strPtr],
            $valuePtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17954'
        );
    }
}
