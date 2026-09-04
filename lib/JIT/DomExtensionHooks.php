<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * dom extension Call + document-method kernel surfaces for lib/JIT (#36204).
 *
 * Implemented in {@code ext/dom/JitDomExtensionHooksFacade.php}; thin Call
 * Dom* proxies and Dom*Runtime linkers must not import {@code ext\dom}.
 */
interface DomExtensionHooks
{
    /**
     * Thin-AOT DOM method Call dispatch.
     *
     * @param non-empty-string $callId stable kernel id (e.g. {@code document.createElement})
     */
    public function invokeCall(Context $context, string $callId, Variable ...$args): Value;

    /**
     * Ensure a thin-AOT document-method ABI bridge is linked.
     *
     * @param non-empty-string $bridgeId id matching ensure{Id}Bridge (e.g. {@code saveXML})
     */
    public function ensureDocumentMethodBridge(Context $context, string $bridgeId): void;
}
