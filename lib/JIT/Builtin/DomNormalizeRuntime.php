<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMNode::normalize() / DOMDocument::normalizeDocument() (#20642). */
final class DomNormalizeRuntime
{
    public const ABI_NORMALIZE = '__phpc_dom_node_normalize';

    public const ABI_NORMALIZE_DOCUMENT = '__phpc_dom_document_normalize_document';

    private const HELPER_PATH = '/ext/dom/DomNormalizeJitHelper.php';

    private const HELPER_NORMALIZE = 'PHPCompiler\\ext\\dom\\DomNormalizeJitHelper::normalizeArgv';

    private const HELPER_NORMALIZE_DOCUMENT = 'PHPCompiler\\ext\\dom\\DomNormalizeJitHelper::normalizeDocumentArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_NORMALIZE,
        self::HELPER_NORMALIZE_DOCUMENT,
    ];

    public static function ensureNormalizeLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureNormalizeBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NORMALIZE,
            'dom_normalize_bridge',
            [$objPtr],
            $context->context->voidType(),
            self::HELPER_NORMALIZE,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20642'
        );
    }

    public static function ensureNormalizeDocumentLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureNormalizeDocumentBridge($context);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NORMALIZE_DOCUMENT,
            'dom_normalize_document_bridge',
            [$objPtr],
            $context->context->voidType(),
            self::HELPER_NORMALIZE_DOCUMENT,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20642'
        );
    }
}
