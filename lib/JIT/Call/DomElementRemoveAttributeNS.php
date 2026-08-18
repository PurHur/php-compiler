<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::removeAttributeNS() — user-script AOT (#32398, php-src returns null). */
final class DomElementRemoveAttributeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_removeattrns_invoke_cont');

        return JitDomAttributeNodeNS::invokeRemoveAttributeNS($context, ...$args);
    }
}
