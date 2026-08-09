<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\CompilerVersion;

/**
 * Engine (Zend) constant use-site deprecations for CONST_FETCH (#29229).
 *
 * php-src: Zend/zend_constants.stub.php / zend_constants.c — {@code E_STRICT} is
 * {@code CONST_DEPRECATED} under PHP 8.4+; fetch emits {@code E_DEPRECATED} while
 * the numeric value remains 2048.
 */
final class EngineConstantDeprecation
{
    /** Bare {@code @deprecated} — no since/message (zend_constants.stub.php). */
    public static function eStrictDeprecatedMetadata(): ?DeprecatedMetadata
    {
        if (!CompilerVersion::supportsEStrictConstantDeprecation()) {
            return null;
        }

        return new DeprecatedMetadata(null, null);
    }

    /**
     * Register engine constants for CONST_FETCH use-site notices
     * ({@see \PHPCompiler\VM} {@code globalConstDeprecated}).
     */
    public static function register(Context $ctx): void
    {
        $meta = self::eStrictDeprecatedMetadata();
        if (null === $meta) {
            return;
        }
        $ctx->globalConstDeprecated['e_strict'] = $meta;
    }
}
