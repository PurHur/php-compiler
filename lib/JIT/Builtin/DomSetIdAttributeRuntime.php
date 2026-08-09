<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;

/** JIT/AOT link for DOMElement::setIdAttribute() (#29257). */
final class DomSetIdAttributeRuntime
{
    public const ABI_TRUE = '__phpc_dom_element_set_id_attribute_true';

    public const ABI_FALSE = '__phpc_dom_element_set_id_attribute_false';

    private const HELPER_PATH = '/ext/dom/DomSetIdAttributeJitHelper.php';

    private const HELPER_TRUE = 'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdTrueArgv';

    private const HELPER_FALSE = 'PHPCompiler\\ext\\dom\\DomSetIdAttributeJitHelper::setIdFalseArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HELPER_TRUE,
        self::HELPER_FALSE,
    ];

    public static function ensureTrueLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetIdAttributeTrueBridge($context);

            return;
        }
        self::link($context, self::ABI_TRUE, 'dom_set_id_attribute_true_bridge', self::HELPER_TRUE);
    }

    public static function ensureFalseLinked(Context $context): void
    {
        if (JitDomDocumentMethodKernel::shouldUse($context)) {
            JitDomDocumentMethodKernel::ensureSetIdAttributeFalseBridge($context);

            return;
        }
        self::link($context, self::ABI_FALSE, 'dom_set_id_attribute_false_bridge', self::HELPER_FALSE);
    }

    private static function link(Context $context, string $abi, string $entry, string $helper): void
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
}
