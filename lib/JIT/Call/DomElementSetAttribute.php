<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::setAttribute() — user-script AOT live Attr (#19281). */
final class DomElementSetAttribute implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattr_invoke_cont');

        return JitDomAttributeNodeNS::invokeSetAttribute($context, ...$args);
    }
}
