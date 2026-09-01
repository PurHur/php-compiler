<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMNode::$baseURI via DomNodeBaseUriJitHelper (ext/dom/node.c). */
final class DomNodeBaseUriRuntime
{
    public const ABI_NAME = '__phpc_dom_node_base_uri';

    private const HELPER_PATH = '/ext/dom/DomNodeBaseUriJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomNodeBaseUriJitHelper::baseUriArgv';

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

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_node_base_uri_bridge',
            [$objPtr],
            $strPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#34904'
        );
    }
}
