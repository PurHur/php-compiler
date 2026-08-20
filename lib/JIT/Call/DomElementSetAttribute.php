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
        // Side-table: assign/box can drop createElement stamps on the local (#32973).
        if (\count($args) >= 3) {
            $name = $args[1]->compileTimeString;
            $value = $args[2]->compileTimeString;
            $id = $args[0]->compileTimeDomElementId ?? \PHPCompiler\ext\dom\JitDomCreateElementAttrs::lastId();
            if (null !== $name && null !== $value && 'xmlns' !== $name && null !== $id) {
                \PHPCompiler\ext\dom\JitDomCreateElementAttrs::set($id, $name, $value);
                $attrs = $args[0]->compileTimeDomAttributes ?? [];
                $attrs[$name] = $value;
                $args[0]->compileTimeDomAttributes = $attrs;
                if (null === $args[0]->compileTimeDomElementId) {
                    $args[0]->compileTimeDomElementId = $id;
                }
            }
        }

        return JitDomAttributeNodeNS::invokeSetAttribute($context, ...$args);
    }
}
