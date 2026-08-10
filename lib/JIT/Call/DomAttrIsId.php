<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\DomUserScriptAttributeCacheLlvm;
use PHPCompiler\ext\dom\JitDomAttrRename;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomAttrIsIdRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DOMAttr::isId() — user-script AOT (#29884).
 *
 * Prefer compile-time setIdAttribute* + getAttributeNode key (Attr cache has no DomRegistry).
 * Fall back to NestedJIT helper for registry-backed Attrs.
 */
final class DomAttrIsId implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_attr_isid_invoke_cont');
        if ([] === $args) {
            throw new \LogicException('DOMAttr::isId() expects receiver');
        }

        if (JitDomAttrRename::lastAttrIsOrphan()) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $key = JitDomAttrRename::lastFetchedKey();
        if (null !== $key) {
            $bearing = DomUserScriptAttributeCacheLlvm::isIdBearingLiteral($key[0], $key[1]);

            return $context->getTypeFromString('int1')->constInt($bearing ? 1 : 0, false);
        }

        return DomAttrIsIdRuntime::invoke($context, $args[0]);
    }
}
