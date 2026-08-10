<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomC14NRuntime;
use PHPCompiler\JIT\Builtin\DomCreateElementNSRuntime;
use PHPCompiler\JIT\Builtin\DomCreateElementRuntime;
use PHPCompiler\JIT\Builtin\DomElementTextContentRuntime;
use PHPCompiler\JIT\Builtin\DomGetElementByIdRuntime;
use PHPCompiler\JIT\Builtin\DomGetElementsByTagNameRuntime;
use PHPCompiler\JIT\Builtin\DomAdoptNodeRuntime;
use PHPCompiler\JIT\Builtin\DomAttrIsIdRuntime;
use PHPCompiler\JIT\Builtin\DomImportNodeRuntime;
use PHPCompiler\JIT\Builtin\DomInstanceMethodRuntime;
use PHPCompiler\JIT\Builtin\DomLivingApiRuntime;
use PHPCompiler\JIT\Builtin\DomLoadHTMLFileRuntime;
use PHPCompiler\JIT\Builtin\DomLoadHTMLRuntime;
use PHPCompiler\JIT\Builtin\DomLoadRuntime;
use PHPCompiler\JIT\Builtin\DomLoadXMLRuntime;
use PHPCompiler\JIT\Builtin\DomNodeChildPropertyRuntime;
use PHPCompiler\JIT\Builtin\DomNodeIsConnectedRuntime;
use PHPCompiler\JIT\Builtin\DomNodeListItemRuntime;
use PHPCompiler\JIT\Builtin\DomNodeLiveMutationRuntime;
use PHPCompiler\JIT\Builtin\DomNodeTreeMutationRuntime;
use PHPCompiler\JIT\Builtin\DomNormalizeRuntime;
use PHPCompiler\JIT\Builtin\DomSaveHTMLFileRuntime;
use PHPCompiler\JIT\Builtin\DomSaveHTMLRuntime;
use PHPCompiler\JIT\Builtin\DomSaveXMLRuntime;
use PHPCompiler\JIT\Builtin\DomSyncElementIdMapRuntime;
use PHPCompiler\JIT\Builtin\DomXPathEvaluateRuntime;
use PHPCompiler\JIT\Builtin\DomXPathQueryRuntime;
use PHPCompiler\JIT\Builtin\DomHtmlDocumentCreateFromStringRuntime;
use PHPCompiler\JIT\Builtin\DomXmlDocumentCreateFromStringRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Builder;

/**
 * Thin standalone AOT: DOM document-method bridges in the main module (#17954, #19496, #20214, #23325).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer ProgressNote #23311).
 * Gate: {@see Context::isThinStandaloneAotMain()} (peer #20200 / #20178 — no NestedJit defer).
 * Housed in ext/dom (not lib/JIT/Builtin) — same kernel-move pattern as #19430 / #19471.
 */
final class JitDomDocumentMethodKernel
{
    public static function shouldUse(Context $context): bool
    {
        return $context->isThinStandaloneAotMain();
    }

    public static function ensureLoadHTMLBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLoadHTMLRuntime::ABI_NAME,
            'dom_load_html_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomLoadHTMLJitHelper::loadHTMLArgv',
            '/ext/dom/DomLoadHTMLJitHelper.php'
        );
    }

    public static function ensureLoadBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLoadRuntime::ABI_NAME,
            'dom_load_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomLoadJitHelper::loadArgv',
            '/ext/dom/DomLoadJitHelper.php'
        );
    }

    public static function ensureLoadHTMLFileBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLoadHTMLFileRuntime::ABI_NAME,
            'dom_load_html_file_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomLoadHTMLFileJitHelper::loadHTMLFileArgv',
            '/ext/dom/DomLoadHTMLFileJitHelper.php'
        );
    }

    public static function ensureGetElementsByTagNameBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomGetElementsByTagNameRuntime::ABI_NAME,
            'dom_get_elements_by_tag_name_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomGetElementsByTagNameJitHelper::getElementsByTagNameStringArgv',
            '/ext/dom/DomGetElementsByTagNameJitHelper.php'
        );
    }

    public static function ensureGetElementByIdBridge(Context $context): void
    {
        self::ensureContextNullableObjectValueBridge(
            $context,
            DomGetElementByIdRuntime::ABI_NAME,
            'dom_get_element_by_id_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__value__*'),
            ],
            'PHPCompiler\\ext\\dom\\DomGetElementByIdJitHelper::getElementByIdArgv',
            '/ext/dom/DomGetElementByIdJitHelper.php'
        );
    }

    public static function ensureImportNodeBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomImportNodeRuntime::ABI_NAME,
            'dom_import_node_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::importNodeArgv',
            '/ext/dom/DomImportNodeJitHelper.php'
        );
    }

    /** DOMDocument::adoptNode() — thin AOT (#29853). */
    public static function ensureAdoptNodeBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomAdoptNodeRuntime::ABI_NAME,
            'dom_adopt_node_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomAdoptNodeJitHelper::adoptNodeArgv',
            '/ext/dom/DomAdoptNodeJitHelper.php'
        );
    }

    public static function ensureGetAttributeBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomImportNodeRuntime::ABI_GET_ATTRIBUTE,
            'dom_get_attribute_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__string__*'),
            'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::getAttributeArgv',
            '/ext/dom/DomImportNodeJitHelper.php'
        );
    }

    public static function ensureGetAttributeNodeNSBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomImportNodeRuntime::ABI_GET_ATTRIBUTE_NODE_NS,
            'dom_get_attribute_node_ns_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::getAttributeNodeNSArgv',
            '/ext/dom/DomImportNodeJitHelper.php'
        );
    }

    public static function ensureSetAttributeNodeNSBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        self::ensureBridge(
            $context,
            DomImportNodeRuntime::ABI_SET_ATTRIBUTE_NODE_NS,
            'dom_set_attribute_node_ns_user_script',
            [$objPtr, $objPtr],
            $objPtr,
            'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::setAttributeNodeNSArgv',
            '/ext/dom/DomImportNodeJitHelper.php'
        );
    }

    public static function ensureCreateAttributeNSBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomImportNodeRuntime::ABI_CREATE_ATTRIBUTE_NS,
            'dom_create_attribute_ns_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::createAttributeNSArgv',
            '/ext/dom/DomImportNodeJitHelper.php'
        );
    }

    public static function ensureCreateAttributeBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomImportNodeRuntime::ABI_CREATE_ATTRIBUTE,
            'dom_create_attribute_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::createAttributeArgv',
            '/ext/dom/DomImportNodeJitHelper.php'
        );
    }

    /** Dom\XMLDocument::createFromString() — thin AOT (#27108). */
    public static function ensureXmlDocumentCreateFromStringBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomXmlDocumentCreateFromStringRuntime::ABI_NAME,
            'dom_xml_document_create_from_string_user_script',
            [
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomXmlDocumentCreateFromStringJitHelper::createFromStringArgv',
            '/ext/dom/DomXmlDocumentCreateFromStringJitHelper.php'
        );
    }

    /** Dom\HTMLDocument::createFromString() — thin AOT (#27300). */
    public static function ensureHtmlDocumentCreateFromStringBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomHtmlDocumentCreateFromStringRuntime::ABI_NAME,
            'dom_html_document_create_from_string_user_script',
            [
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomHtmlDocumentCreateFromStringJitHelper::createFromStringArgv',
            '/ext/dom/DomHtmlDocumentCreateFromStringJitHelper.php'
        );
    }

    public static function ensureSetAttributeNodeBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomImportNodeRuntime::ABI_SET_ATTRIBUTE_NODE,
            'dom_set_attribute_node_user_script',
            [$context->getTypeFromString('__object__*'), $context->getTypeFromString('__object__*')],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::setAttributeNodeArgv',
            '/ext/dom/DomImportNodeJitHelper.php'
        );
    }

    public static function ensureFirstChildBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_FIRST_CHILD,
            'dom_first_child_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_FIRST,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureLastChildBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_LAST_CHILD,
            'dom_last_child_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_LAST,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureXPathQueryBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomXPathQueryRuntime::ABI_NAME,
            'dom_xpath_query_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomXPathQueryJitHelper::queryStringArgv',
            '/ext/dom/DomXPathQueryJitHelper.php'
        );
    }

    public static function ensureXPathEvaluateBridge(Context $context): void
    {
        self::ensureXPathEvaluateBoolBridge($context);
        self::ensureXPathEvaluateDoubleBridge($context);
        self::ensureXPathEvaluateStringBridge($context);
    }

    public static function ensureXPathEvaluateBoolBridge(Context $context): void
    {
        self::ensureContextBoolValueBridge(
            $context,
            DomXPathEvaluateRuntime::ABI_BOOL,
            'dom_xpath_evaluate_bool_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            self::BOOL_HELPER,
            '/ext/dom/DomXPathEvaluateJitHelper.php'
        );
    }

    public static function ensureXPathEvaluateDoubleBridge(Context $context): void
    {
        self::ensureContextDoubleValueBridge(
            $context,
            DomXPathEvaluateRuntime::ABI_DOUBLE,
            'dom_xpath_evaluate_double_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            self::DOUBLE_HELPER,
            '/ext/dom/DomXPathEvaluateJitHelper.php'
        );
    }

    public static function ensureXPathEvaluateStringBridge(Context $context): void
    {
        self::ensureContextStringValueBridge(
            $context,
            DomXPathEvaluateRuntime::ABI_STRING,
            'dom_xpath_evaluate_string_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            self::STRING_HELPER,
            '/ext/dom/DomXPathEvaluateJitHelper.php'
        );
    }

    private const BOOL_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateBoolArgv';

    private const DOUBLE_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateDoubleArgv';

    private const STRING_HELPER = 'PHPCompiler\\ext\\dom\\DomXPathEvaluateJitHelper::evaluateStringArgv';

    public static function ensureNodeListItemBridge(Context $context): void
    {
        self::ensureNullableObjectValueBridge(
            $context,
            DomNodeListItemRuntime::ABI_NAME,
            'dom_nodelist_item_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('int64'),
            ],
            'PHPCompiler\\ext\\dom\\DomNodeListItemJitHelper::itemIntArgv',
            '/ext/dom/DomNodeListItemJitHelper.php'
        );
    }

    public static function ensureLoadXMLBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLoadXMLRuntime::ABI_NAME,
            'dom_load_xml_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomLoadXMLJitHelper::loadXMLArgv',
            '/ext/dom/DomLoadXMLJitHelper.php'
        );
    }

    public static function ensureSaveHTMLBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomSaveHTMLRuntime::ABI_NAME,
            'dom_save_html_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__string__*'),
            'PHPCompiler\\ext\\dom\\DomSaveHTMLJitHelper::saveHTMLArgv',
            '/ext/dom/DomSaveHTMLJitHelper.php'
        );
    }

    public static function ensureSaveXMLBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomSaveXMLRuntime::ABI_NAME,
            'dom_save_xml_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__value__*'),
            ],
            $context->getTypeFromString('__string__*'),
            'PHPCompiler\\ext\\dom\\DomSaveXMLJitHelper::saveXMLArgv',
            '/ext/dom/DomSaveXMLJitHelper.php'
        );
    }

    public static function ensureCreateElementBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomCreateElementRuntime::ABI_NAME,
            'dom_create_element_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createElementArgv',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureNormalizeBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        self::ensureBridge(
            $context,
            DomNormalizeRuntime::ABI_NORMALIZE,
            'dom_normalize_user_script',
            [$objPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomNormalizeJitHelper::normalizeArgv',
            '/ext/dom/DomNormalizeJitHelper.php'
        );
    }

    public static function ensureNormalizeDocumentBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        self::ensureBridge(
            $context,
            DomNormalizeRuntime::ABI_NORMALIZE_DOCUMENT,
            'dom_normalize_document_user_script',
            [$objPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomNormalizeJitHelper::normalizeDocumentArgv',
            '/ext/dom/DomNormalizeJitHelper.php'
        );
    }

    public static function ensureCreateElementNSBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomCreateElementNSRuntime::ABI_NAME,
            'dom_create_element_ns_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('__string__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomCreateElementNSJitHelper::createElementNSArgv',
            '/ext/dom/DomCreateElementNSJitHelper.php'
        );
    }

    public static function ensureSaveHTMLFileBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomSaveHTMLFileRuntime::ABI_NAME,
            'dom_save_html_file_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('int64'),
            'PHPCompiler\\ext\\dom\\DomSaveHTMLFileJitHelper::saveHTMLFileArgv',
            '/ext/dom/DomSaveHTMLFileJitHelper.php'
        );
    }

    public static function ensureElementTextContentBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomElementTextContentRuntime::ABI_NAME,
            'dom_element_text_content_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__string__*'),
            'PHPCompiler\\ext\\dom\\DomElementTextContentJitHelper::textContentArgv',
            '/ext/dom/DomElementTextContentJitHelper.php'
        );
    }

    public static function ensureElementTextContentWriteBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomElementTextContentRuntime::ABI_WRITE_TEXT_CONTENT,
            'dom_element_text_content_write_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomElementTextContentJitHelper::writeTextContentArgv',
            '/ext/dom/DomElementTextContentJitHelper.php'
        );
    }

    public static function ensureElementNodeValueWriteBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomElementTextContentRuntime::ABI_WRITE_NODE_VALUE,
            'dom_element_node_value_write_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomElementTextContentJitHelper::writeNodeValueArgv',
            '/ext/dom/DomElementTextContentJitHelper.php'
        );
    }

    public static function ensureIsConnectedBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeIsConnectedRuntime::ABI_NAME,
            'dom_node_is_connected_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('int64'),
            'PHPCompiler\\ext\\dom\\DomIsConnectedJitHelper::isConnectedArgv',
            '/ext/dom/DomIsConnectedJitHelper.php'
        );
    }

    public static function ensureSyncElementIdMapBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomSyncElementIdMapRuntime::ABI_NAME,
            'dom_sync_element_id_map_user_script',
            [
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('void'),
            'PHPCompiler\\ext\\dom\\DomSyncElementIdMapJitHelper::syncArgv',
            '/ext/dom/DomSyncElementIdMapJitHelper.php'
        );
    }

    public static function ensureAppendBridge(Context $context, int $arity): void
    {
        self::ensureLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::appendAbi($arity),
            'dom_append_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendArgv'.$arity
        );
    }

    public static function ensureAppendObjectBridge(Context $context, int $arity): void
    {
        self::ensureObjectLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::appendObjectAbi($arity),
            'dom_append_object_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendObjectArgv'.$arity
        );
    }

    public static function ensurePrependBridge(Context $context, int $arity): void
    {
        self::ensureLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::prependAbi($arity),
            'dom_prepend_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependArgv'.$arity
        );
    }

    public static function ensurePrependObjectBridge(Context $context, int $arity): void
    {
        self::ensureObjectLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::prependObjectAbi($arity),
            'dom_prepend_object_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependObjectArgv'.$arity
        );
    }

    public static function ensureAppendStringBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeLiveMutationRuntime::appendStringAbi(),
            'dom_append_string_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::appendStringArgv1',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensurePrependStringBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeLiveMutationRuntime::prependStringAbi(),
            'dom_prepend_string_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::prependStringArgv1',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureReplaceChildrenBridge(Context $context, int $arity): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $paramTypes = [$objPtr];
        for ($i = 0; $i < $arity; ++$i) {
            $paramTypes[] = $valuePtr;
        }
        self::ensureBridge(
            $context,
            DomNodeLiveMutationRuntime::replaceChildrenAbi($arity),
            'dom_replace_children_user_script_'.$arity,
            $paramTypes,
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildrenArgv'.$arity,
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureReplaceChildrenObjectBridge(Context $context, int $arity): void
    {
        self::ensureObjectLiveMutationBridge(
            $context,
            DomNodeLiveMutationRuntime::replaceChildrenObjectAbi($arity),
            'dom_replace_children_object_user_script_'.$arity,
            $arity,
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildrenObjectArgv'.$arity,
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureReplaceChildrenStringBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeLiveMutationRuntime::replaceChildrenStringAbi(),
            'dom_replace_children_string_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildrenStringArgv1',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureContainsBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLivingApiRuntime::ABI_CONTAINS,
            'dom_node_contains_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomContainsJitHelper::containsArgv',
            '/ext/dom/DomContainsJitHelper.php'
        );
    }

    public static function ensureCompareDocumentPositionBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLivingApiRuntime::ABI_COMPARE_DOCUMENT_POSITION,
            'dom_node_compare_document_position_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('int64'),
            'PHPCompiler\\ext\\dom\\DomCompareDocumentPositionJitHelper::compareDocumentPositionArgv',
            '/ext/dom/DomCompareDocumentPositionJitHelper.php'
        );
    }

    public static function ensureContainsNullBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLivingApiRuntime::ABI_CONTAINS_NULL,
            'dom_node_contains_null_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomContainsNullJitHelper::containsNullArgv',
            '/ext/dom/DomContainsNullJitHelper.php'
        );
    }

    public static function ensureGetRootNodeBridge(Context $context): void
    {
        self::ensureContextObjectValueBridge(
            $context,
            DomLivingApiRuntime::ABI_GET_ROOT_NODE,
            'dom_node_get_root_node_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            'PHPCompiler\\ext\\dom\\DomGetRootNodeJitHelper::getRootNodeArgv',
            '/ext/dom/DomGetRootNodeJitHelper.php'
        );
    }

    public static function ensureIsEqualNodeBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLivingApiRuntime::ABI_IS_EQUAL_NODE,
            'dom_node_is_equal_node_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomIsEqualNodeJitHelper::isEqualNodeArgv',
            '/ext/dom/DomIsEqualNodeJitHelper.php'
        );
    }

    public static function ensureAttrIsIdBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomAttrIsIdRuntime::ABI_NAME,
            'dom_attr_is_id_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\\ext\\dom\\DomAttrIsIdJitHelper::isIdArgv',
            '/ext/dom/DomAttrIsIdJitHelper.php'
        );
    }

    public static function ensureC14NBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomC14NRuntime::ABI_NAME,
            'dom_node_c14n_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('int64'),
            ],
            $context->getTypeFromString('__value__*'),
            'PHPCompiler\\ext\\dom\\DomC14NJitHelper::c14nArgv',
            '/ext/dom/DomC14NJitHelper.php'
        );
    }

    public static function ensureToggleAttributeBridge(Context $context): void
    {
        self::ensureToggleAttributeOmitBridge($context);
    }

    public static function ensureToggleAttributeOmitBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLivingApiRuntime::ABI_TOGGLE_ATTRIBUTE_OMIT,
            'dom_element_toggle_attribute_omit_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\ext\dom\DomToggleAttributeJitHelper::toggleOmitArgv',
            '/ext/dom/DomToggleAttributeJitHelper.php'
        );
    }

    public static function ensureToggleAttributeForceTrueBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLivingApiRuntime::ABI_TOGGLE_ATTRIBUTE_FORCE_TRUE,
            'dom_element_toggle_attribute_force_true_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\ext\dom\DomToggleAttributeJitHelper::toggleForceTrueArgv',
            '/ext/dom/DomToggleAttributeJitHelper.php'
        );
    }

    public static function ensureToggleAttributeForceFalseBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomLivingApiRuntime::ABI_TOGGLE_ATTRIBUTE_FORCE_FALSE,
            'dom_element_toggle_attribute_force_false_user_script',
            [
                $context->getTypeFromString('__object__*'),
                $context->getTypeFromString('__string__*'),
            ],
            $context->getTypeFromString('int1'),
            'PHPCompiler\ext\dom\DomToggleAttributeJitHelper::toggleForceFalseArgv',
            '/ext/dom/DomToggleAttributeJitHelper.php'
        );
    }

    public static function ensureSetIdAttributeTrueBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        self::ensureBridge(
            $context,
            \PHPCompiler\JIT\Builtin\DomSetIdAttributeRuntime::ABI_TRUE,
            'dom_element_set_id_attribute_true_user_script',
            [$objPtr, $strPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdTrueArgv',
            '/ext/dom/DomSetIdAttributeJitHelper.php'
        );
    }

    public static function ensureSetIdAttributeFalseBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        self::ensureBridge(
            $context,
            \PHPCompiler\JIT\Builtin\DomSetIdAttributeRuntime::ABI_FALSE,
            'dom_element_set_id_attribute_false_user_script',
            [$objPtr, $strPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdFalseArgv',
            '/ext/dom/DomSetIdAttributeJitHelper.php'
        );
    }

    public static function ensureSetIdAttributeNsTrueBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        self::ensureBridge(
            $context,
            \PHPCompiler\JIT\Builtin\DomSetIdAttributeRuntime::ABI_NS_TRUE,
            'dom_element_set_id_attribute_ns_true_user_script',
            [$objPtr, $strPtr, $strPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdNsTrueArgv',
            '/ext/dom/DomSetIdAttributeJitHelper.php'
        );
    }

    public static function ensureSetIdAttributeNsFalseBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        self::ensureBridge(
            $context,
            \PHPCompiler\JIT\Builtin\DomSetIdAttributeRuntime::ABI_NS_FALSE,
            'dom_element_set_id_attribute_ns_false_user_script',
            [$objPtr, $strPtr, $strPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdNsFalseArgv',
            '/ext/dom/DomSetIdAttributeJitHelper.php'
        );
    }

    public static function ensureSetIdAttributeNodeTrueBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        self::ensureBridge(
            $context,
            \PHPCompiler\JIT\Builtin\DomSetIdAttributeRuntime::ABI_NODE_TRUE,
            'dom_element_set_id_attribute_node_true_user_script',
            [$objPtr, $objPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdNodeTrueArgv',
            '/ext/dom/DomSetIdAttributeJitHelper.php'
        );
    }

    public static function ensureSetIdAttributeNodeFalseBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        self::ensureBridge(
            $context,
            \PHPCompiler\JIT\Builtin\DomSetIdAttributeRuntime::ABI_NODE_FALSE,
            'dom_element_set_id_attribute_node_false_user_script',
            [$objPtr, $objPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdNodeFalseArgv',
            '/ext/dom/DomSetIdAttributeJitHelper.php'
        );
    }

    public static function ensureRemoveChildBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        self::ensureBridge(
            $context,
            DomNodeTreeMutationRuntime::ABI_REMOVE_CHILD,
            'dom_remove_child_user_script',
            [$objPtr, $objPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::removeChildObjectArgv1',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureReplaceChildBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        self::ensureBridge(
            $context,
            DomNodeTreeMutationRuntime::ABI_REPLACE_CHILD,
            'dom_replace_child_user_script',
            [$objPtr, $objPtr, $objPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::replaceChildObjectArgv2',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureInsertBeforeBridge(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        self::ensureBridge(
            $context,
            DomNodeTreeMutationRuntime::ABI_INSERT_BEFORE,
            'dom_insert_before_user_script',
            [$objPtr, $objPtr, $objPtr],
            $context->context->voidType(),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::insertBeforeObjectArgv2',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureParentNodeBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_PARENT_NODE,
            'dom_parent_node_user_script',
            [$context->getTypeFromString('__object__*')],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_PARENT,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureNextSiblingBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_NEXT_SIBLING,
            'dom_next_sibling_user_script',
            [$context->getTypeFromString('__object__*')],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_NEXT,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensurePreviousSiblingBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_PREVIOUS_SIBLING,
            'dom_previous_sibling_user_script',
            [$context->getTypeFromString('__object__*')],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_PREV,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureFirstElementChildBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_FIRST_ELEMENT_CHILD,
            'dom_first_element_child_user_script',
            [$context->getTypeFromString('__object__*')],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_FIRST_ELEMENT,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureLastElementChildBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_LAST_ELEMENT_CHILD,
            'dom_last_element_child_user_script',
            [$context->getTypeFromString('__object__*')],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_LAST_ELEMENT,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureChildElementCountBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_CHILD_ELEMENT_COUNT,
            'dom_child_element_count_user_script',
            [$context->getTypeFromString('__object__*')],
            $context->getTypeFromString('int64'),
            DomNodeChildPropertyRuntime::HELPER_CHILD_ELEMENT_COUNT,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureNextElementSiblingBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_NEXT_ELEMENT_SIBLING,
            'dom_next_element_sibling_user_script',
            [$context->getTypeFromString('__object__*')],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_NEXT_ELEMENT,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensurePreviousElementSiblingBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeChildPropertyRuntime::ABI_PREVIOUS_ELEMENT_SIBLING,
            'dom_previous_element_sibling_user_script',
            [$context->getTypeFromString('__object__*')],
            $context->getTypeFromString('__object__*'),
            DomNodeChildPropertyRuntime::HELPER_PREV_ELEMENT,
            '/ext/dom/DomNodeChildPropertyJitHelper.php'
        );
    }

    public static function ensureCreateDocumentFragmentBridge(Context $context): void
    {
        self::ensureContextBridge(
            $context,
            DomNodeLiveMutationRuntime::ABI_CREATE_FRAGMENT,
            'dom_create_document_fragment_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createDocumentFragmentArgv',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    public static function ensureCreateDocumentFragmentObjectBridge(Context $context): void
    {
        self::ensureBridge(
            $context,
            DomNodeLiveMutationRuntime::ABI_CREATE_FRAGMENT_OBJECT,
            'dom_create_document_fragment_object_user_script',
            [
                $context->getTypeFromString('__object__*'),
            ],
            $context->getTypeFromString('__object__*'),
            'PHPCompiler\\ext\\dom\\DomCreateElementJitHelper::createDocumentFragmentObjectArgv',
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    private static function ensureObjectLiveMutationBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        int $arity,
        string $helperLogical,
        string $helperPath = '/ext/dom/DomCreateElementJitHelper.php'
    ): void {
        $objPtr = $context->getTypeFromString('__object__*');
        $paramTypes = [$objPtr];
        for ($i = 0; $i < $arity; ++$i) {
            $paramTypes[] = $objPtr;
        }
        self::ensureBridge(
            $context,
            $abi,
            $entryBlock,
            $paramTypes,
            $context->context->voidType(),
            $helperLogical,
            $helperPath
        );
    }

    private static function ensureLiveMutationBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        int $arity,
        string $helperLogical
    ): void {
        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $paramTypes = [$objPtr];
        for ($i = 0; $i < $arity; ++$i) {
            $paramTypes[] = $valuePtr;
        }
        self::ensureBridge(
            $context,
            $abi,
            $entryBlock,
            $paramTypes,
            $context->context->voidType(),
            $helperLogical,
            '/ext/dom/DomCreateElementJitHelper.php'
        );
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        \PHPLLVM\Type $returnType,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        // Save before nested helper compile — NestedJitCompileScope detaches the builder (#22680).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#18268');
        $ft = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $result = $context->builder->call($helperFn, ...$args);
        if ('void' === $context->getStringFromType($returnType)) {
            $context->builder->returnVoid();
        } else {
            $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $returnType);
            $context->builder->returnValue($ret);
        }
        $context->registerFunction($abi, $fn);

        self::restoreInsertAfterBridge($context, $savedBlock);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        \PHPLLVM\Type $returnType,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        // Save before nested helper compile — NestedJitCompileScope detaches the builder (#22680).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#17954');
        $ft = $context->context->functionType($returnType, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i)->typeOf()
            );
        }
        $result = $context->builder->call($helperFn, ...$args);
        if ('void' === $context->getStringFromType($returnType)) {
            $context->builder->returnVoid();
        } else {
            $ret = JitNestedHelperCoerce::coerceBridgeResult($context, $result, $returnType);
            $context->builder->returnValue($ret);
        }
        $context->registerFunction($abi, $fn);

        self::restoreInsertAfterBridge($context, $savedBlock);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextObjectValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        // Save before nested helper compile — NestedJitCompileScope detaches the builder (#22680).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#18461');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $foundObj = $context->builder->call($helperFn, ...$args);
        $foundObj = JitNestedHelperCoerce::coerceBridgeResult($context, $foundObj, $objPtr);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $foundObj
        );
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        // Never clearInsertionPosition mid-user-script — orphaned entryAlloca GEPs (#19507).
        self::restoreInsertAfterBridge($context, $savedBlock);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextNullableObjectValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        // Save before nested helper compile — NestedJitCompileScope detaches the builder (#22680).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#17954');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $foundObj = $context->builder->call($helperFn, ...$args);
        $foundObj = JitNestedHelperCoerce::coerceBridgeResult($context, $foundObj, $objPtr);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $foundObj,
            $objPtr->constNull()
        );
        $nullBlock = $fn->appendBasicBlock('dom_gei_bridge_null');
        $objBlock = $fn->appendBasicBlock('dom_gei_bridge_obj');
        $doneBlock = $fn->appendBasicBlock('dom_gei_bridge_done');
        $context->builder->branchIf($isNull, $nullBlock, $objBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($objBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $foundObj
        );
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        // Never clearInsertionPosition mid-user-script — orphaned entryAlloca GEPs (#19507).
        self::restoreInsertAfterBridge($context, $savedBlock);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureNullableObjectValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        // Save before nested helper compile — NestedJitCompileScope detaches the builder (#22680).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $objPtr = $context->getTypeFromString('__object__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#17954');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $args = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i)->typeOf()
            );
        }
        $foundObj = $context->builder->call($helperFn, ...$args);
        $foundObj = JitNestedHelperCoerce::coerceBridgeResult($context, $foundObj, $objPtr);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $foundObj,
            $objPtr->constNull()
        );
        $nullBlock = $fn->appendBasicBlock('dom_gei_bridge_null');
        $objBlock = $fn->appendBasicBlock('dom_gei_bridge_obj');
        $doneBlock = $fn->appendBasicBlock('dom_gei_bridge_done');
        $context->builder->branchIf($isNull, $nullBlock, $objBlock);
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $destPtr);
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($objBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $destPtr,
            $foundObj
        );
        $context->builder->branch($doneBlock);
        $context->builder->positionAtEnd($doneBlock);
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        // Never clearInsertionPosition mid-user-script — orphaned entryAlloca GEPs (#19507).
        self::restoreInsertAfterBridge($context, $savedBlock);
    }

    private static function ensureNestedHelperProxies(Context $context): void
    {
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        NestedVmVariableMethodLlvm::ensureMethod($context, 'resolveindirect');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toobject');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tostring');
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
    }

    /**
     * @param list<string> $compiledHelpers
     */
    public static function compileMainModuleHelpers(
        Context $context,
        string $relativePath,
        array $compiledHelpers
    ): void {
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $relativePath, $compiledHelpers);
    }

    /**
     * @param list<string> $compiledHelpers
     */
    private static function ensureMainModuleHelperCompiled(
        Context $context,
        string $relativePath,
        array $compiledHelpers
    ): void {
        JitVmHelperLink::ensureCompiled(
            $context,
            $relativePath,
            $compiledHelpers,
            '#23325'
        );
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextBoolValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        // Save before nested helper compile — NestedJitCompileScope detaches the builder (#22680).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#18526');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $truthy = $context->builder->call($helperFn, ...$args);
        $truthy = JitNestedHelperCoerce::coerceBridgeResult($context, $truthy, $i64);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool(
            $context,
            $destPtr,
            $context->builder->icmp(Builder::INT_NE, $truthy, $i64->constInt(0, false))
        );
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        // Never clearInsertionPosition mid-user-script — orphaned entryAlloca GEPs (#19507).
        self::restoreInsertAfterBridge($context, $savedBlock);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextDoubleValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        // Save before nested helper compile — NestedJitCompileScope detaches the builder (#22680).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $valuePtr = $context->getTypeFromString('__value__*');
        $doubleTy = $context->getTypeFromString('double');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#18526');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $number = $context->builder->call($helperFn, ...$args);
        $number = JitNestedHelperCoerce::coerceBridgeResult($context, $number, $doubleTy);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $destPtr,
            $number
        );
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        // Never clearInsertionPosition mid-user-script — orphaned entryAlloca GEPs (#19507).
        self::restoreInsertAfterBridge($context, $savedBlock);
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    private static function ensureContextStringValueBridge(
        Context $context,
        string $abi,
        string $entryBlock,
        array $paramTypes,
        string $helperLogical,
        string $helperPath
    ): void {
        $probe = $context->module->getNamedFunction($abi);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, $entryBlock)) {
            $context->registerFunction($abi, $probe);

            return;
        }

        // Save before nested helper compile — NestedJitCompileScope detaches the builder (#22680).
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureNestedHelperProxies($context);
        self::ensureMainModuleHelperCompiled($context, $helperPath, [$helperLogical]);

        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $helperFn = JitVmHelperLink::lookupCompiled($context, $helperLogical, '#19352');
        $ft = $context->context->functionType($valuePtr, false, ...$paramTypes);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abi, $ft);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, $entryBlock);
        $context->builder->positionAtEnd($entry);
        $vmCtx = $context->builder->call(VmActiveContextLlvm::lookupAbi($context));
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $vmCtx,
                $helperFn->getParam(0)->typeOf()
            ),
        ];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $args[] = JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $fn->getParam($i),
                $helperFn->getParam($i + 1)->typeOf()
            );
        }
        $string = $context->builder->call($helperFn, ...$args);
        $string = JitNestedHelperCoerce::coerceBridgeResult($context, $string, $strPtr);
        $slot = JitValueBox::alloc($context);
        $destPtr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $destPtr,
            $string
        );
        $context->builder->returnValue(JitValueBox::normalizeValuePtr($context, $destPtr));
        $context->registerFunction($abi, $fn);

        // Never clearInsertionPosition mid-user-script — orphaned entryAlloca GEPs (#19507).
        self::restoreInsertAfterBridge($context, $savedBlock);
    }

    /**
     * Restore the caller's insert block after bridge emit (mirror JitVmHelperLink::ensureBridge).
     * Prefer a fresh open block on the caller's function when the saved block is terminated (#19507).
     */
    private static function restoreInsertAfterBridge(Context $context, $savedBlock): void
    {
        if (null !== $savedBlock) {
            if (null === $savedBlock->getTerminator()) {
                $context->builder->positionAtEnd($savedBlock);

                return;
            }
            $parent = $savedBlock->getParent();
            if ($parent instanceof \PHPLLVM\Value\Function_) {
                $next = $parent->appendBasicBlock('dom_bridge_restore_cont');
                $context->builder->positionAtEnd($next);

                return;
            }
        }
        $fallback = null;
        if ('' !== $context->activeFunction && isset($context->functions[$context->activeFunction])) {
            $active = $context->functions[$context->activeFunction];
            if ($active instanceof \PHPLLVM\Value\Function_) {
                $fallback = $active;
            }
        }
        if (null === $fallback && $context->main instanceof \PHPLLVM\Value\Function_) {
            $fallback = $context->main;
        }
        if (null !== $fallback && $fallback->countBasicBlocks() > 0) {
            $next = $fallback->appendBasicBlock('dom_bridge_restore_main_cont');
            $context->builder->positionAtEnd($next);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}