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

/** DOMElement::setAttribute() — user-script AOT live Attr (#19281). */
final class DomElementSetAttribute implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_setattr_invoke_cont');
        $id = null;
        // Side-table: assign/box can drop createElement stamps on the local (#32973).
        if (\count($args) >= 3) {
            $name = $args[1]->compileTimeString;
            $value = $args[2]->compileTimeString;
            $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
            if (null !== $name && null !== $value && 'xmlns' !== $name && null !== $id) {
                JitDomCreateElementAttrs::set($id, $name, $value);
                // Merge side-table first — local stamp alone would wipe NS attrs from
                // a prior setAttributeNS on the same element (#34257 / peer #33526).
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                if ([] === $attrs) {
                    $attrs = JitDomCreateElementAttrs::get($id);
                }
                $attrs[$name] = $value;
                $args[0]->compileTimeDomAttributes = $attrs;
                if (null === $args[0]->compileTimeDomElementId) {
                    $args[0]->compileTimeDomElementId = $id;
                }
            }
            // loadXML documentElement C14N fold (#32981). Nested paths invalidate.
            if (null !== $name && null !== $value && 'xmlns' !== $name) {
                $path = $args[0]->compileTimeDomNodePath ?? null;
                $nested = null !== $path && '' !== $path
                    && substr_count(trim($path, '/'), '/') >= 1;
                if ($nested) {
                    JitDomLoadXMLUserScript::markTreeMutatedSinceLoad();
                } else {
                    JitDomLoadXMLUserScript::refreshCompileTimeXmlRootAttributeSet($name, $value);
                }
            }
        }

        $result = JitDomAttributeNodeNS::invokeSetAttribute($context, ...$args);

        // saveXML / INNER_XML rebuild read PROP_USER_SCRIPT_XMLNS_ATTR (#33509 / peer #33362).
        if (\count($args) >= 3) {
            $name = $args[1]->compileTimeString;
            $value = $args[2]->compileTimeString;
            if (null !== $name && null !== $value && 'xmlns' !== $name) {
                $attrs = $args[0]->compileTimeDomAttributes;
                if (null === $attrs && null !== $id) {
                    $attrs = JitDomCreateElementAttrs::get($id);
                }
                if (null !== $attrs) {
                    JitDomAttributeNodeNS::syncSaveXmlAttrSuffix($context, $args[0], $attrs);
                }
            }
        }

        return $result;
    }
}
