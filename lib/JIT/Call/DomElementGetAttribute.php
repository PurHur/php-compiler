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
