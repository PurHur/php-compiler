<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * User-script AOT: NS attribute node slots (#19268).
 *
 * Each compile-time (namespace, localName) pair maps to a module global holding
 * a DOMAttr*. Avoids DomRegistry bridges that segfault in standalone AOT.
 */
final class DomUserScriptAttributeCacheLlvm
{
    /** @var array<string, Value> */
    private static array $slotByKey = [];

    /** @var object|null */
    private static $moduleIdentity = null;

    private static ?string $lastCreateNamespace = null;

    private static ?string $lastCreateLocalName = null;

    public static function rememberCreate(string $namespace, string $qualifiedName): void
    {
        $pos = strpos($qualifiedName, ':');
        $local = false === $pos ? $qualifiedName : substr($qualifiedName, $pos + 1);
        self::$lastCreateNamespace = $namespace;
        self::$lastCreateLocalName = $local;
    }

    public static function lastCreateNamespace(): ?string
    {
        return self::$lastCreateNamespace;
    }

    public static function lastCreateLocalName(): ?string
    {
        return self::$lastCreateLocalName;
    }

    public static function storeLiteral(
        Context $context,
        string $namespace,
        string $localName,
        Value $attr
    ): Value {
        $global = self::slotGlobal($context, $namespace, $localName);
        $prev = $context->builder->load($global);
        $context->builder->store($attr, $global);

        return $prev;
    }

    public static function lookupLiteral(
        Context $context,
        string $namespace,
        string $localName
    ): Value {
        $global = self::slotGlobal($context, $namespace, $localName);

        return $context->builder->load($global);
    }

    private static function slotGlobal(Context $context, string $namespace, string $localName): Value
    {
        $module = $context->module;
        if (self::$moduleIdentity !== $module) {
            self::$moduleIdentity = $module;
            self::$slotByKey = [];
            self::$lastCreateNamespace = null;
            self::$lastCreateLocalName = null;
        }

        $key = $namespace."\0".$localName;
        if (isset(self::$slotByKey[$key])) {
            return self::$slotByKey[$key];
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $globalName = '__phpc_dom_us_attr_'.substr(hash('crc32b', $key), 0, 8);
        $existing = $module->getNamedGlobal($globalName);
        if (null !== $existing) {
            self::$slotByKey[$key] = $existing;

            return $existing;
        }

        $global = $module->addGlobal($objPtr, $globalName);
        $global->setInitializer($objPtr->constNull());
        self::$slotByKey[$key] = $global;

        return $global;
    }
}
