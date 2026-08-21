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

/** DOMElement::setAttributeNode() — user-script AOT (#20676 / #33570). */
final class DomElementSetAttributeNode implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattrnode_invoke_cont');

        $result = JitDomAttributeNodeNS::invokeSetAttributeNode($context, ...$args);

        // saveXML / INNER_XML rebuild read PROP_USER_SCRIPT_XMLNS_ATTR (#33570 / peer #33509).
        // createAttribute + Attr::$value = lit leaves getAttribute working but markup empty
        // without this sync (setAttribute already updates the bag + suffix).
        if (\count($args) >= 2) {
            $local = DomUserScriptAttributeCacheLlvm::lastCreateLocalName();
            $ns = DomUserScriptAttributeCacheLlvm::lastCreateNamespace();
            $value = null !== $local
                ? (DomUserScriptAttributeCacheLlvm::literalValue($ns ?? '', $local)
                    ?? DomUserScriptAttributeCacheLlvm::pendingLiteralValue())
                : null;
            if (null !== $local && null !== $value && 'xmlns' !== $local) {
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                foreach (DomUserScriptAttributeCacheLlvm::presentNonNsAttrsForSaveXml() as $n => $v) {
                    $attrs[$n] = $v;
                }
                $attrs[$local] = $value;
                $args[0]->compileTimeDomAttributes = $attrs;
                $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
                if (null !== $id) {
                    JitDomCreateElementAttrs::set($id, $local, $value);
                    if (null === $args[0]->compileTimeDomElementId) {
                        $args[0]->compileTimeDomElementId = $id;
                    }
                }
                JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
            }
        }

        return $result;
    }
}
