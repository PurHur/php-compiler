<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMElement::setIdAttribute{,NS,Node}() (#29257, #29284). */
final class DomSetIdAttributeRuntime
{
    public const ABI_TRUE = '__phpc_dom_element_set_id_attribute_true';

    public const ABI_FALSE = '__phpc_dom_element_set_id_attribute_false';

    public const ABI_NS_TRUE = '__phpc_dom_element_set_id_attribute_ns_true';

    public const ABI_NS_FALSE = '__phpc_dom_element_set_id_attribute_ns_false';

    public const ABI_NODE_TRUE = '__phpc_dom_element_set_id_attribute_node_true';

    public const ABI_NODE_FALSE = '__phpc_dom_element_set_id_attribute_node_false';

    private const HELPER_PATH = '/ext/dom/DomSetIdAttributeJitHelper.php';

    private const HELPER_TRUE = 'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdTrueArgv';

    private const HELPER_FALSE = 'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdFalseArgv';

    private const HELPER_NS_TRUE = 'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdNsTrueArgv';

    private const HELPER_NS_FALSE = 'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdNsFalseArgv';

    private const HELPER_NODE_TRUE = 'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdNodeTrueArgv';

    private const HELPER_NODE_FALSE = 'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdNodeFalseArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_TRUE,
        self::HELPER_FALSE,
        self::HELPER_NS_TRUE,
        self::HELPER_NS_FALSE,
        self::HELPER_NODE_TRUE,
        self::HELPER_NODE_FALSE,
    ];

    public static function ensureTrueLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetIdAttributeTrueBridge($context);

            return;
        }
        self::linkName($context, self::ABI_TRUE, 'dom_set_id_attribute_true_bridge', self::HELPER_TRUE);
    }

    public static function ensureFalseLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetIdAttributeFalseBridge($context);

            return;
        }
        self::linkName($context, self::ABI_FALSE, 'dom_set_id_attribute_false_bridge', self::HELPER_FALSE);
    }

    public static function ensureNsTrueLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetIdAttributeNsTrueBridge($context);

            return;
        }
        self::linkNs($context, self::ABI_NS_TRUE, 'dom_set_id_attribute_ns_true_bridge', self::HELPER_NS_TRUE);
    }

    public static function ensureNsFalseLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetIdAttributeNsFalseBridge($context);

            return;
        }
        self::linkNs($context, self::ABI_NS_FALSE, 'dom_set_id_attribute_ns_false_bridge', self::HELPER_NS_FALSE);
    }

    public static function ensureNodeTrueLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetIdAttributeNodeTrueBridge($context);

            return;
        }
        self::linkNode($context, self::ABI_NODE_TRUE, 'dom_set_id_attribute_node_true_bridge', self::HELPER_NODE_TRUE);
    }

    public static function ensureNodeFalseLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetIdAttributeNodeFalseBridge($context);

            return;
        }
        self::linkNode($context, self::ABI_NODE_FALSE, 'dom_set_id_attribute_node_false_bridge', self::HELPER_NODE_FALSE);
    }

    private static function linkName(Context $context, string $abi, string $entry, string $helper): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            $entry,
            [$objPtr, $strPtr],
            $context->context->voidType(),
            $helper,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29257'
        );
    }

    private static function linkNs(Context $context, string $abi, string $entry, string $helper): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            $entry,
            [$objPtr, $strPtr, $strPtr],
            $context->context->voidType(),
            $helper,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29284'
        );
    }

    private static function linkNode(Context $context, string $abi, string $entry, string $helper): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            $abi,
            $entry,
            [$objPtr, $objPtr],
            $context->context->voidType(),
            $helper,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29284'
        );
    }
}
