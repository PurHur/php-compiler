<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * session_create_id() semantics for compiled JIT/AOT modules (#9500, php-in-PHP).
 *
 * SSOT: {@see VmSession::createId}
 * php-src: ext/session/session.c — php_session_create_id
 */
final class SessionCreateIdJitHelper
{
    public static function randomIdString(): string
    {
        $result = VmSession::createId(null);
        if (!\is_string($result)) {
            throw new \LogicException('session random id must be string (#9500)');
        }

        return $result;
    }

    /** @return string|null null when php-src session_create_id() would return false */
    public static function createIdNullable(?string $prefix): ?string
    {
        $result = VmSession::createId($prefix);
        if (false === $result) {
            return null;
        }

        return $result;
    }
}
