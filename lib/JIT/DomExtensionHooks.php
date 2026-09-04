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

    /**
     * Thin-AOT DOM instance-method bridge (VmDomInstanceInvoke arity 0–4).
     */
    public function ensureInstanceMethodBridge(Context $context, int $extraArgCount): void;

    /**
     * Thin-AOT DomStandaloneAotInit class registration bridge.
     */
    public function ensureStandaloneAotInit(Context $context): void;

    /**
     * Z_PARAM_OBJ_OF_CLASS(DOMNode) guard — Zend TypeError (#30410).
     *
     * @return bool true when compile-time invalid type was handled (caller must return)
     */
    public function requireDomNodeArgGuardOrAbort(
        Context $context,
        Variable $arg,
        string $function,
        int $userArgIndex,
        string $paramName,
        string $expectedClass = 'DOMNode'
    ): bool;

    /**
     * Living-API toggleAttribute int1 emit (user-script AOT).
     *
     * @param non-empty-string $mode omit|force_true|force_false
     */
    public function emitToggleAttributeInt1(
        Context $context,
        Value $element,
        string $nameLit,
        string $mode
    ): Value;
}
