<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomUserScriptAttributeCacheLlvm;
use PHPCompiler\ext\dom\JitDomAttrRename;
use PHPCompiler\ext\dom\JitDomAttributeNodeNS;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomAttrIsIdRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMAttr::isId() — user-script AOT (#29884).
 *
 * Always runtime via NestedJIT — compile-time idBearing stamps mis-fold when CFG
 * lowering order differs from source order (maintainer_gap #29884).
 */
final class DomAttrIsId implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_attr_isid_invoke_cont');
        JitDomAttributeNodeNS::ensureClassicAttrMethods($context);
        if ([] === $args) {
            throw new \LogicException('DOMAttr::isId() expects receiver');
        }

        if (JitDomAttrRename::lastAttrIsOrphan()) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $key = JitDomAttrRename::lastFetchedKey();
        if (null !== $key) {
            $active = strtolower($context->activeFunction);
            $inUserDeclaredFunction = '' !== $active
                && !str_starts_with($active, '__')
                && in_array($active, $context->userFunctionNames(), true);
            // loadXML DTD ATTLIST ID / xml:id / setIdAttribute* stamp compile-time flags (#34821).
            // Module-wide idBearing stamps pollute user-function CFG paths (#23514 importNode).
            if (!$inUserDeclaredFunction
                && DomUserScriptAttributeCacheLlvm::isIdBearingLiteral($key[0], $key[1])
            ) {
                return $context->getTypeFromString('int1')->constInt(1, false);
            }
            if ($inUserDeclaredFunction && '' === $key[0] && 'id' === $key[1]) {
                // createElement id= toggles via module global — runtime stores from setIdAttribute (#29884).
                return DomUserScriptAttributeCacheLlvm::loadIdBearingGlobal($context);
            }

            return DomAttrIsIdRuntime::invoke($context, $args[0]);
        }

        return DomAttrIsIdRuntime::invoke($context, $args[0]);
    }
}
