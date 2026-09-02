<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\JIT\Call;

/**
 * Bind `object::method()` static proxies to NestedVm Variable/HashTable/Object helpers under
 * SPINE_CHUNK — ext/ds and peers lower boxed ops as object:: when the receiver class is not
 * a compile-time literal (#36147 / #36155 follow-up).
 */
final class SpineChunkNestedVmBind
{
    /** HashTable PHP method name → NestedVm handler key (lowercase). */
    private const OBJECT_METHOD_ALIASES = [
        'findvariable' => 'find',
    ];

    public static function tryBindObjectStaticProxy(Context $context, string $proxyLc): ?Call
    {
        if (!ExternalMethodBind::spineChunkMode()) {
            return null;
        }
        $proxyLc = strtolower(ltrim($proxyLc, '\\'));
        if (!str_starts_with($proxyLc, 'object::')) {
            return null;
        }
        $methodLc = substr($proxyLc, strlen('object::'));
        if ('' === $methodLc) {
            return null;
        }
        $handlerKey = self::OBJECT_METHOD_ALIASES[$methodLc] ?? $methodLc;

        if (NestedVmVariableMethodLlvm::isNestedVariableMethod($handlerKey)) {
            return self::aliasVmProxy($context, $proxyLc, 'phpcompiler\\vm\\variable::'.$handlerKey, static function () use ($context, $handlerKey): bool {
                return NestedVmVariableMethodLlvm::ensureMethod($context, $handlerKey);
            });
        }
        if (NestedVmHashTableMethodLlvm::isNestedHashTableMethod($handlerKey)) {
            return self::aliasVmProxy($context, $proxyLc, 'phpcompiler\\vm\\hashtable::'.$handlerKey, static function () use ($context, $handlerKey): bool {
                return NestedVmHashTableMethodLlvm::ensureMethod($context, $handlerKey);
            });
        }
        if (NestedVmObjectMethodLlvm::isNestedObjectMethod($handlerKey)) {
            return self::aliasVmProxy($context, $proxyLc, 'phpcompiler\\vm\\objectentry::'.$handlerKey, static function () use ($context, $handlerKey): bool {
                return NestedVmObjectMethodLlvm::ensureMethod($context, $handlerKey);
            });
        }
        if (NestedContextMethodLlvm::isNestedContextMethod($handlerKey)) {
            return self::aliasVmProxy($context, $proxyLc, 'phpcompiler\\vm\\context::'.$handlerKey, static function () use ($context, $handlerKey): bool {
                return NestedContextMethodLlvm::ensureMethod($context, $handlerKey);
            });
        }
        if ('coercevariabletostring' === $handlerKey) {
            $vmProxy = 'phpcompiler\\vm::coercevariabletostring';
            if (!$context->functionIsRegistered($vmProxy)) {
                $context->functionProxies[$vmProxy] = new Call\VmCoerceVariableToString();
            }

            return self::aliasVmProxy($context, $proxyLc, $vmProxy, static fn (): bool => true);
        }

        return null;
    }

    /**
     * @param callable(): bool $ensure
     */
    private static function aliasVmProxy(Context $context, string $objectProxyLc, string $vmProxyLc, callable $ensure): ?Call
    {
        $vmProxyLc = strtolower(ltrim($vmProxyLc, '\\'));
        if (!$ensure()) {
            return null;
        }
        if (!$context->functionIsRegistered($vmProxyLc)) {
            return null;
        }
        $call = $context->functionProxies[$vmProxyLc];
        if ($call instanceof Call\ExternalMethod) {
            return null;
        }
        $context->functionProxies[$objectProxyLc] = $call;

        return $call;
    }
}
