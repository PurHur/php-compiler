<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMNode::$isConnected via DomIsConnectedJitHelper (#19653). */
final class DomNodeIsConnectedRuntime
{
    public const ABI_NAME = '__phpc_dom_node_is_connected';

    private const HELPER_PATH = '/ext/dom/DomIsConnectedJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomIsConnectedJitHelper::isConnectedArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureIsConnectedBridge($context);

            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_node_is_connected_bridge',
            [$objPtr],
            $i64,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19653'
        );
    }
}
