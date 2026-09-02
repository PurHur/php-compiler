<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\JIT\Call;

/**
 * Lazy {@see Call} handlers for cross-chunk stdlib surfaces under SPINE_CHUNK (#36155).
 *
 * Consumer chunks omit the full Context builtin table; these proxies are safe without
 * loading the declaring PHP class into the chunk module.
 */
final class SpineChunkIntrinsicCallBind
{
    /** @var array<string, class-string<Call>> */
    private const HANDLERS = [
        'exception::__tostring' => Call\ExceptionToString::class,
        'throwable::__tostring' => Call\ExceptionToString::class,
    ];

    public static function tryBind(Context $context, string $proxyName): ?Call
    {
        if (!ExternalMethodBind::spineChunkMode()) {
            return null;
        }
        $lc = strtolower(ltrim($proxyName, '\\'));
        $class = self::HANDLERS[$lc] ?? null;
        if (null === $class) {
            return null;
        }
        if (isset($context->functionProxies[$lc])) {
            $existing = $context->functionProxies[$lc];
            if (!$existing instanceof Call\ExternalMethod) {
                return $existing;
            }
        }
        $call = new $class();
        $context->functionProxies[$lc] = $call;

        return $call;
    }
}
