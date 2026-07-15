<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMDocument::importNode() via DomImportNodeJitHelper (#19212). */
final class DomImportNodeRuntime
{
    public const ABI_NAME = '__phpc_dom_import_node';

    public const ABI_GET_ATTRIBUTE = '__phpc_dom_get_attribute';

    private const HELPER_PATH = '/ext/dom/DomImportNodeJitHelper.php';

    private const HELPER = 'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::importNodeArgv';

    private const HELPER_GET_ATTR = 'PHPCompiler\\ext\\dom\\DomImportNodeJitHelper::getAttributeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER,
        self::HELPER_GET_ATTR,
    ];

    public static function ensureLinked(Context $context): void
    {
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureImportNodeBridge($context);

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
        if (DomDocumentMethodUserScriptLlvm::shouldUse($context)) {
            DomDocumentMethodUserScriptLlvm::ensureGetAttributeBridge($context);

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
}
