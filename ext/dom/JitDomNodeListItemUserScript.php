<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMNodeList::item() (#18493). */
final class JitDomNodeListItemUserScript
{
    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }
        $index = $args[1]->compileTimeLong;
        if (null === $index && null !== $args[1]->compileTimeString && is_numeric($args[1]->compileTimeString)) {
            $index = (int) $args[1]->compileTimeString;
        }
        if (null === $index || 0 !== $index) {
            return null;
        }
        $cacheKey = JitDomXPathQueryUserScript::lastCacheKey();
        if (null === $cacheKey) {
            return null;
        }
        $keyStr = $context->builder->load($context->constantStringFromString($cacheKey));
        $found = DomUserScriptElementCacheLlvm::lookupObject($context, $keyStr);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $found
        );

        return JitValueBox::normalizeValuePtr($context, $ptr);
    }
}
