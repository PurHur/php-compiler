<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * dom extension Call surfaces needed by lib/JIT Call Dom* (#36204).
 *
 * Implemented in {@code ext/dom/JitDomExtensionHooksFacade.php}; thin Call
 * Dom* proxies must not import {@code ext\dom}.
 */
interface DomExtensionHooks
{
    /**
     * Thin-AOT DOM method Call dispatch.
     *
     * @param non-empty-string $callId stable kernel id (e.g. {@code document.createElement})
     */
    public function invokeCall(Context $context, string $callId, Variable ...$args): Value;
}
