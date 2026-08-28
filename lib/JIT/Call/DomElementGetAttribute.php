<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomCreateElementAttrs;
use PHPCompiler\ext\dom\JitDomImportNode;
use PHPCompiler\ext\dom\JitDomNamedNodeMap;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMElement::getAttribute() — user-script AOT (#19212, live Attr #19281, #27108, #34863).
 *
 * Prefer the receiver's compile-time open-tag stamp, then the element's own
 * attributes NamedNodeMap pins (php-src xmlGetProp). Never fall back to
 * process-global lastFetchedAttributes / name→value Attr cache — those collapse
 * sibling id= values after lastChild or getElementById (#34863 / re-#34050).
 */
final class DomElementGetAttribute implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattr_invoke_cont');
        $nameLit = null;
        if (isset($args[1])) {
            $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        }

        // Per-element open-tag stamp from firstChild/nextSibling only when still on
        // this Variable (#34050). Do not use lastFetchedAttributes — ARG_SEND /
        // getElementById receivers would inherit the last sibling's attrs (#34863).
        if (null !== $nameLit && isset($args[0])) {
            $attrs = $args[0]->compileTimeDomAttributes;
            if (null !== $attrs && [] !== $attrs) {
                $val = $attrs[$nameLit] ?? null;
                if (null === $val || '' === $val) {
                    $pos = strpos($nameLit, ':');
                    if (false !== $pos) {
                        $val = $attrs[substr($nameLit, $pos + 1)] ?? null;
                    }
                }
                if (null !== $val && '' !== $val) {
                    return self::boxConstantString($context, $val);
                }
            }
            // replaceChild clears the attrs bag on the return; read CreateElementAttrs (#35386).
            $id = $args[0]->compileTimeDomElementId ?? null;
            if (null !== $id) {
                $fromSide = JitDomCreateElementAttrs::get($id);
                if ([] !== $fromSide) {
                    $val = $fromSide[$nameLit] ?? null;
                    if (null === $val || '' === $val) {
                        $pos = strpos($nameLit, ':');
                        if (false !== $pos) {
                            $val = $fromSide[substr($nameLit, $pos + 1)] ?? null;
                        }
                    }
                    if (null !== $val && '' !== $val) {
                        return self::boxConstantString($context, $val);
                    }
                }
            }
        }

        // Per-element NamedNodeMap pins — correct after lastChild / getElementById
        // and for Attr::$value writes on attached attributes (#34863 / #19281).
        if (isset($args[0], $args[1])) {
            return JitDomNamedNodeMap::invokeElementGetAttribute($context, $args[0], $args[1]);
        }

        // Otherwise fall back to importNode/getElementById HTML-id stub (#19212).
        return JitDomImportNode::invokeGetAttribute($context, ...$args);
    }

    private static function boxConstantString(Context $context, string $lit): Value
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
