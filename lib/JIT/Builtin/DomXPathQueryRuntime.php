<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;


use PHPCompiler\JIT\Context;

/** JIT/AOT link for DOMXPath::query() (DomXPathQueryJitHelper (#18493)) — DomExtensionHooks (#36204). */
final class DomXPathQueryRuntime
{
    public const ABI_NAME = '__phpc_dom_xpath_query';

    private const HELPER_PATH = '/ext/dom/DomXPathQueryJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomXPathQueryJitHelper::queryStringArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if ($context->extensionLowering->shouldUseDomDocumentMethodKernel($context)) {
            $context->extensionLowering->requireDom()->ensureDocumentMethodBridge($context, 'XPathQuery');

            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $context->extensionLowering->requireDom()->ensureDocumentMethodBridge($context, 'XPathQuery');
    }
}
