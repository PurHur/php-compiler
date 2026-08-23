<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomUserScriptAttributeCacheLlvm;
use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\ext\dom\JitDomImportNode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::getAttribute() — user-script AOT (#19212, live Attr #19281, #27108). */
final class DomElementGetAttribute implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattr_invoke_cont');
        $nameLit = null;
        if (isset($args[1])) {
            $nameLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        }

        // Per-element open-tag stamp from firstChild/nextSibling (#34050) — before the
        // global name→value Attr cache, which collapses multiple id= to the last seed.
        // ARG_SEND temps drop compileTimeDomAttributes; lastFetchedAttributes is cleared
        // on createElement / documentElement so it cannot leak across documents.
        if (null !== $nameLit) {
            $attrs = (isset($args[0]) ? $args[0]->compileTimeDomAttributes : null)
                ?? \PHPCompiler\ext\dom\JitDomNodeChildProperty::$lastFetchedAttributes;
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
        }

        // Live Attr::$value first — setAttribute stores presentByKey without valueByKey, and
        // Attr::$value writes must update getAttribute (php-src attr.c; #19281 / #29642).
        // The compile-time valueByKey shortcut below is only for parse-time Attrs that never
        // got an object slot (or as fallback when the live cache miss returns empty).
        if (JitDomAttributeNodeNS::userScriptAttrCacheHasName($context, $args[1] ?? null)) {
            return JitDomAttributeNodeNS::invokeGetAttributeLive($context, ...$args);
        }

        if (null !== $nameLit && DomUserScriptAttributeCacheLlvm::hasPresentLiteral('', $nameLit)) {
            return self::boxConstantString(
                $context,
                DomUserScriptAttributeCacheLlvm::literalValue('', $nameLit) ?? ''
            );
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
