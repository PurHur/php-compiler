<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMNode::{removeChild,replaceChild} (#19240). */
final class DomNodeTreeMutationRuntime
{
    public const ABI_REMOVE_CHILD = '__phpc_dom_node_remove_child';

    public const ABI_REPLACE_CHILD = '__phpc_dom_node_replace_child';

    private const HELPER_PATH = '/ext/dom/DomCreateElementJitHelper.php';

    private const HELPER_REMOVE = 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::removeChildObjectArgv1';

    private const HELPER_REPLACE = 'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildObjectArgv2';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_REMOVE,
        self::HELPER_REPLACE,
    ];

    public static function ensureRemoveChildLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureRemoveChildBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_REMOVE_CHILD,
            'dom_remove_child_bridge',
            [$objPtr, $objPtr],
            $context->context->voidType(),
            self::HELPER_REMOVE,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19240'
        );
    }

    public static function ensureReplaceChildLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureReplaceChildBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_REPLACE_CHILD,
            'dom_replace_child_bridge',
            [$objPtr, $objPtr, $objPtr],
            $context->context->voidType(),
            self::HELPER_REPLACE,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19240'
        );
    }
}
