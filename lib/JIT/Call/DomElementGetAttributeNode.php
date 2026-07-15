<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::getAttributeNode() — user-script AOT live Attr (#19281). */
final class DomElementGetAttributeNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_getattrnode_invoke_cont');

        return JitDomAttributeNodeNS::invokeGetAttributeNode($context, ...$args);
    }
}
