<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\Context;

/**
 * Per-JIT-Context encoding shadows for thin AOT fold of mb_* (#20014).
 *
 * Stored by spl_object_id so we do not edit {@see Context} (helper-runtime
 * core fingerprint) and state does not leak across compiles in one process.
 */
final class MbstringAotFoldState
{
    /** @var array<int, array{http?: string, internal?: string, detect?: list<string>}> */
    private static array $byContext = [];

    public static function httpOutput(Context $context): ?string
    {
        return self::$byContext[spl_object_id($context)]['http'] ?? null;
    }

    public static function setHttpOutput(Context $context, string $encoding): void
    {
        self::$byContext[spl_object_id($context)]['http'] = $encoding;
    }

    public static function internalEncoding(Context $context): ?string
    {
        return self::$byContext[spl_object_id($context)]['internal'] ?? null;
    }

    public static function setInternalEncoding(Context $context, string $encoding): void
    {
        self::$byContext[spl_object_id($context)]['internal'] = $encoding;
    }

    /** @return list<string>|null */
    public static function detectOrder(Context $context): ?array
    {
        return self::$byContext[spl_object_id($context)]['detect'] ?? null;
    }

    /** @param list<string> $order */
    public static function setDetectOrder(Context $context, array $order): void
    {
        self::$byContext[spl_object_id($context)]['detect'] = $order;
    }
}
