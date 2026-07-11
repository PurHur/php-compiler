<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/**
 * JIT/AOT link for DOMDocument::loadHTML() via DomLoadHTMLJitHelper PHP (#17954).
 */
final class DomLoadHTMLRuntime
{
    public const ABI_NAME = '__phpc_dom_load_html';

    private const HELPER_PATH = '/ext/dom/DomLoadHTMLJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomLoadHTMLJitHelper::loadHTMLArgv';

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
            DomDocumentMethodUserScriptLlvm::ensureLoadHTMLBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_load_html_bridge',
            [$objPtr, $strPtr, $i64],
            $i1,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#17954'
        );
    }
}
