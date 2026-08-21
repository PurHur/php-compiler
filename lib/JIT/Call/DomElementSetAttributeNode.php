<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomUserScriptAttributeCacheLlvm;
use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\ext\dom\JitDomCreateElementAttrs;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::setAttributeNode() — user-script AOT (#20676). */
final class DomElementSetAttributeNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrnode_invoke_cont');

        $result = JitDomAttributeNodeNS::invokeSetAttributeNode($context, ...$args);

        // saveXML / INNER_XML rebuild read PROP_USER_SCRIPT_XMLNS_ATTR (#33570 / peer #33509).
        $local = DomUserScriptAttributeCacheLlvm::lastCreateLocalName();
        $ns = DomUserScriptAttributeCacheLlvm::lastCreateNamespace() ?? '';
        if (null !== $local && \count($args) >= 1) {
            $value = DomUserScriptAttributeCacheLlvm::literalValue($ns, $local) ?? '';
            $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
            if (null !== $id) {
                JitDomCreateElementAttrs::set($id, $local, $value);
                $attrs = JitDomCreateElementAttrs::get($id);
            } else {
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                $attrs[$local] = $value;
            }
            $args[0]->compileTimeDomAttributes = $attrs;
            JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
        }

        return $result;
    }
}
