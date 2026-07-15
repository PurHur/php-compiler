<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\ext\dom\JitDomImportNode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::getAttribute() — user-script AOT (#19212, live Attr #19281). */
final class DomElementGetAttribute implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattr_invoke_cont');

        // Live Attr cache when setAttribute/getAttributeNode populated it (#19281);
        // otherwise fall back to importNode/getElementById HTML-id stub (#19212).
        if (JitDomAttributeNodeNS::userScriptAttrCacheHasName($context, $args[1] ?? null)) {
            return JitDomAttributeNodeNS::invokeGetAttributeLive($context, ...$args);
        }

        return JitDomImportNode::invokeGetAttribute($context, ...$args);
    }
}
