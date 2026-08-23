<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\ext\dom\JitDomCreateElementAttrs;
use PHPCompiler\ext\dom\JitDomLoadXMLUserScript;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMElement::removeAttribute() — user-script AOT (#19870). */
final class DomElementRemoveAttribute implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_removeattr_invoke_cont');
        $id = null;
        $hadLoadXml = null !== JitDomLoadXMLUserScript::lastCompileTimeXml();
        $didRefreshRootXml = false;
        // Keep createElement attr bag + loadXML C14N fold in sync (#32981 / #34257).
        if (\count($args) >= 2) {
            $name = $args[1]->compileTimeString;
            if (null !== $name && 'xmlns' !== $name) {
                $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
                if (null !== $id) {
                    JitDomCreateElementAttrs::remove($id, $name);
                }
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                if (isset($attrs[$name])) {
                    unset($attrs[$name]);
                    $args[0]->compileTimeDomAttributes = $attrs;
                }
                $path = $args[0]->compileTimeDomNodePath ?? null;
                $nested = null !== $path && '' !== $path
                    && substr_count(trim($path, '/'), '/') >= 1;
                if ($nested) {
                    JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();
                } else {
                    JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeRemove($name);
                    $didRefreshRootXml = $hadLoadXml;
                }
            }
        }

        $result = JitDomAttributeNodeNS::invokeRemoveAttribute($context, ...$args);

        // Drop attr from saveXML open-tag suffix (#33509 / loadXML #34257).
        if (\count($args) >= 2) {
            $name = $args[1]->compileTimeString;
            if (null !== $name && 'xmlns' !== $name) {
                if ($didRefreshRootXml) {
                    JitDomLoadXMLUserScript::syncElementXmlnsAttrFromCompileTimeXml($context, $args[0]);
                } else {
                    $attrs = $args[0]->compileTimeDomAttributes;
                    if (null === $attrs && null !== $id) {
                        $attrs = JitDomCreateElementAttrs::get($id);
                    }
                    if (null !== $attrs) {
                        JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
                    }
                }
            }
        }

        return $result;
    }
}
