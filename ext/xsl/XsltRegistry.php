<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;

/** Per-instance host XSLTProcessor state (#3665). */
final class XsltRegistry
{
    /** @var array<int, \XSLTProcessor> */
    private static array $processors = [];

    /**
     * Namespaced XSLT PHP callables from registerPHPFunctionNS() (#22243).
     *
     * @var array<int, array<string, array<string, \PHPCompiler\VM\Variable>>>
     */
    private static array $phpFunctionNs = [];

    /**
     * registerPHPFunctions() restrict payload + VM context for php:function bridge (#22632).
     *
     * @var array<int, array{ctx: Context, restrict: null|string|list<string>}>
     */
    private static array $phpFunctions = [];

    public static function attach(ObjectEntry $entry, \XSLTProcessor $processor): void
    {
        self::$processors[$entry->id] = $processor;
        self::$phpFunctionNs[$entry->id] = [];
        unset(self::$phpFunctions[$entry->id]);
    }

    public static function has(ObjectEntry $entry): bool
    {
        return isset(self::$processors[$entry->id]);
    }

    public static function processor(ObjectEntry $entry): \XSLTProcessor
    {
        if (!isset(self::$processors[$entry->id])) {
            throw new \LogicException('XSLTProcessor has no registered processor state in this compiler build');
        }

        return self::$processors[$entry->id];
    }

    /**
     * @param null|string|list<string> $restrict
     */
    public static function storePhpFunctions(ObjectEntry $entry, Context $ctx, null|string|array $restrict): void
    {
        self::$phpFunctions[$entry->id] = [
            'ctx' => $ctx,
            'restrict' => $restrict,
        ];
    }

    public static function hasPhpFunctions(ObjectEntry $entry): bool
    {
        return isset(self::$phpFunctions[$entry->id]);
    }

    /**
     * @return array{ctx: Context, restrict: null|string|list<string>}|null
     */
    public static function phpFunctions(ObjectEntry $entry): ?array
    {
        return self::$phpFunctions[$entry->id] ?? null;
    }

    public static function storePhpFunctionNS(
        ObjectEntry $entry,
        string $namespaceUri,
        string $name,
        \PHPCompiler\VM\Variable $callable
    ): void {
        if (!isset(self::$phpFunctionNs[$entry->id])) {
            self::$phpFunctionNs[$entry->id] = [];
        }
        if (!isset(self::$phpFunctionNs[$entry->id][$namespaceUri])) {
            self::$phpFunctionNs[$entry->id][$namespaceUri] = [];
        }
        self::$phpFunctionNs[$entry->id][$namespaceUri][$name] = $callable;
    }
}
