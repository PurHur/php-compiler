<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::setAttributeNodeNS() — user-script AOT (#19265, #33578). */
final class DomElementSetAttributeNodeNS implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrnodens_invoke_cont');

        $result = JitDomAttributeNodeNS::invokeSet($context, ...$args);
        // saveXML / INNER_XML rebuild read PROP_USER_SCRIPT_XMLNS_ATTR (#33526 / #33578).
        if (\count($args) >= 2) {
            JitDomAttributeNodeNS::syncSaveXmlAttrSuffixAfterSetAttributeNode($context, $args[0]);
        }

        return $result;
    }
}
