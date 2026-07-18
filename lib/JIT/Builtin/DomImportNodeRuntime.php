<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::importNode() via DomImportNodeJitHelper (#19212). */
final class DomImportNodeRuntime
{
    public const ABI_NAME = '__phpc_dom_import_node';

    public const ABI_GET_ATTRIBUTE = '__phpc_dom_get_attribute';

    public const ABI_GET_ATTRIBUTE_NODE_NS = '__phpc_dom_get_attribute_node_ns';

    public const ABI_SET_ATTRIBUTE_NODE_NS = '__phpc_dom_set_attribute_node_ns';

    public const ABI_CREATE_ATTRIBUTE_NS = '__phpc_dom_create_attribute_ns';

    public const ABI_CREATE_ATTRIBUTE = '__phpc_dom_create_attribute';

    public const ABI_SET_ATTRIBUTE_NODE = '__phpc_dom_set_attribute_node';

    private const HELPER_PATH = '/ext/dom/DomImportNodeJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::importNodeArgv';

    private const HELPER_GET_ATTR = 'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::getAttributeArgv';

    private const HELPER_GET_ATTR_NODE_NS = 'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::getAttributeNodeNSArgv';

    private const HELPER_SET_ATTR_NODE_NS = 'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::setAttributeNodeNSArgv';

    private const HELPER_SET_ATTR_NODE = 'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::setAttributeNodeArgv';

    private const HELPER_CREATE_ATTR_NS = 'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::createAttributeNSArgv';

    private const HELPER_CREATE_ATTR = 'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::createAttributeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
        self::HELPER_GET_ATTR,
        self::HELPER_GET_ATTR_NODE_NS,
        self::HELPER_SET_ATTR_NODE_NS,
        self::HELPER_SET_ATTR_NODE,
        self::HELPER_CREATE_ATTR_NS,
        self::HELPER_CREATE_ATTR,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureImportNodeBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NAME,
            'dom_import_node_bridge',
            [$objPtr, $objPtr, $i64],
            $objPtr,
            self::HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19212'
        );
    }

    public static function ensureGetAttributeLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureGetAttributeBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_GET_ATTRIBUTE,
            'dom_get_attribute_bridge',
            [$objPtr, $strPtr],
            $strPtr,
            self::HELPER_GET_ATTR,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19212'
        );
    }

    public static function ensureGetAttributeNodeNSLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureGetAttributeNodeNSBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_GET_ATTRIBUTE_NODE_NS,
            'dom_get_attribute_node_ns_bridge',
            [$objPtr, $strPtr, $strPtr],
            $objPtr,
            self::HELPER_GET_ATTR_NODE_NS,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19265'
        );
    }

    public static function ensureSetAttributeNodeNSLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetAttributeNodeNSBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SET_ATTRIBUTE_NODE_NS,
            'dom_set_attribute_node_ns_bridge',
            [$objPtr, $objPtr],
            $objPtr,
            self::HELPER_SET_ATTR_NODE_NS,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19265'
        );
    }

    public static function ensureCreateAttributeNSLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureCreateAttributeNSBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CREATE_ATTRIBUTE_NS,
            'dom_create_attribute_ns_bridge',
            [$objPtr, $strPtr, $strPtr],
            $objPtr,
            self::HELPER_CREATE_ATTR_NS,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#19265'
        );
    }

    public static function ensureCreateAttributeLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureCreateAttributeBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_CREATE_ATTRIBUTE,
            'dom_create_attribute_bridge',
            [$objPtr, $strPtr],
            $objPtr,
            self::HELPER_CREATE_ATTR,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20676'
        );
    }

    public static function ensureSetAttributeNodeLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetAttributeNodeBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_SET_ATTRIBUTE_NODE,
            'dom_set_attribute_node_bridge',
            [$objPtr, $objPtr],
            $objPtr,
            self::HELPER_SET_ATTR_NODE,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20676'
        );
    }
}
