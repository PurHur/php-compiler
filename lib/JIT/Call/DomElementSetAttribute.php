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
        // Side-table: assign/box can drop createElement stamps on the local (#32973).
        if (\count($args) >= 3) {
            $name = $args[1]->compileTimeString;
            $value = $args[2]->compileTimeString;
            $id = $args[0]->compileTimeDomElementId ?? JitDomCreateElementAttrs::lastId();
            if (null !== $name && null !== $value && 'xmlns' !== $name && null !== $id) {
                JitDomCreateElementAttrs::set($id, $name, $value);
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
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

        return JitDomAttributeNodeNS::invokeSetAttribute($context, ...$args);
    }
}
