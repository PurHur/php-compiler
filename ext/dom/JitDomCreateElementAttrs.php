<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * Compile-time attribute bag for createElement results (#32973).
 *
 * Locals often lose createElement Variable stamps after assign/boxing; keep
 * tag+attrs in a side table keyed by id, with {@see $lastId} fallback.
 */
final class JitDomCreateElementAttrs
{
    private static int $nextId = 0;

    private static ?int $lastId = null;

    /** @var array<int, string> */
    private static array $tagById = [];

    /** @var array<int, array<string, string>> */
    private static array $attrsById = [];

    public static function nextId(string $tag): int
    {
        $id = ++self::$nextId;
        self::$tagById[$id] = $tag;
        self::$attrsById[$id] = [];
        self::$lastId = $id;

        return $id;
    }

    public static function lastId(): ?int
    {
        return self::$lastId;
    }

    public static function tag(int $id): ?string
    {
        return self::$tagById[$id] ?? null;
    }

    public static function set(int $id, string $name, string $value): void
    {
        if (!isset(self::$attrsById[$id])) {
            self::$attrsById[$id] = [];
        }
        self::$attrsById[$id][$name] = $value;
    }

    /** @return array<string, string> */
    public static function get(int $id): array
    {
        return self::$attrsById[$id] ?? [];
    }

    public static function reset(): void
    {
        self::$nextId = 0;
        self::$lastId = null;
        self::$tagById = [];
        self::$attrsById = [];
    }
}
