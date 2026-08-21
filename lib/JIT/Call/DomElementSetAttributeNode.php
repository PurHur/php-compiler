<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::setAttributeNode() — user-script AOT (#20676, #33570). */
final class DomElementSetAttributeNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrnode_invoke_cont');

        $result = JitDomAttributeNodeNS::invokeSetAttributeNode($context, ...$args);
        // saveXML / INNER_XML rebuild read PROP_USER_SCRIPT_XMLNS_ATTR (#33509 / #33570).
        if (\count($args) >= 2) {
            JitDomAttributeNodeNS::syncSaveXmlAttrSuffixAfterSetAttributeNode($context, $args[0]);
        }

        return $result;
    }
}
