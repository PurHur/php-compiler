<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for DOMNodeList::item() via DomNodeListItemJitHelper (#18493). */
final class DomNodeListItemRuntime
{
    public const ABI_NAME = '__phpc_dom_nodelist_item';

    private const HELPER_PATH = '/ext/dom/DomNodeListItemJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomNodeListItemJitHelper::itemIntArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureNodeListItemBridge($context);

            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        JitDomDocumentMethodKernel::ensureNodeListItemBridge($context);
    }
}
