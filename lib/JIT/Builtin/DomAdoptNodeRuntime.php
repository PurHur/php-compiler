<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::adoptNode() via DomAdoptNodeJitHelper (#29853). */
final class DomAdoptNodeRuntime
{
    public const ABI_NAME = '__phpc_dom_adopt_node';

    private const HELPER_PATH = '/ext/dom/DomAdoptNodeJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomAdoptNodeJitHelper::adoptNodeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureAdoptNodeBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_adopt_node_bridge',
            [$objPtr, $objPtr],
            $objPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29853'
        );
    }
}
