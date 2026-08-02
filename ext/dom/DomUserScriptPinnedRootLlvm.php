<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * User-script AOT: pin loadXML documentElement for NodeList::item() identity (#26752).
 *
 * getElementsByTagName() builds a length-only NodeList; item(0) must return the same
 * child object linked under the pinned root (parentNode set) so ChildNode mutators
 * can update {@see VmDom::PROP_USER_SCRIPT_INNER_XML}.
 */
final class DomUserScriptPinnedRootLlvm
{
    public const GLOBAL_DOC_ELEMENT = '__phpc_dom_us_pinned_document_element';

    public static function pin(Context $context, Value $documentElement): void
    {
        self::ensureGlobal($context);
        $context->builder->store(
            $documentElement,
            $context->module->getNamedGlobal(self::GLOBAL_DOC_ELEMENT)
        );
    }

    public static function load(Context $context): ?Value
    {
        self::ensureGlobal($context);
        $global = $context->module->getNamedGlobal(self::GLOBAL_DOC_ELEMENT);
        if (null === $global) {
            return null;
        }

        return $context->builder->load($global);
    }

    private static function ensureGlobal(Context $context): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        if (null !== $context->module->getNamedGlobal(self::GLOBAL_DOC_ELEMENT)) {
            return;
        }
        $g = $context->module->addGlobal($objPtr, self::GLOBAL_DOC_ELEMENT);
        $g->setInitializer($objPtr->constNull());
    }
}
