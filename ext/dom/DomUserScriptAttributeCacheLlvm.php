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
 *
 * State is keyed by LLVM module id so NestedJIT module switches do not wipe
 * the main-module present/value maps (#27108 rename dup check).
 */
final class DomUserScriptAttributeCacheLlvm
{
    /**
     * @var array<int, array{
     *   slotByKey: array<string, Value>,
     *   presentByKey: array<string, true>,
     *   valueByKey: array<string, string>,
     *   idBearingByKey: array<string, true>,
     *   lastCreateNamespace: ?string,
     *   lastCreateLocalName: ?string
     * }>
     */
    private static array $byModule = [];

    public static function rememberCreate(string $namespace, string $qualifiedName): void
    {
        // rememberCreate is compile-time only; bind to whatever module is current later via store.
        $pos = strpos($qualifiedName, ':');
        $local = false === $pos ? $qualifiedName : substr($qualifiedName, $pos + 1);
        self::$pendingCreate = [$namespace, $local];
    }

    /** @var null|array{0: string, 1: string} */
    private static ?array $pendingCreate = null;

    public static function lastCreateNamespace(): ?string
    {
        return self::$pendingCreate[0] ?? null;
    }

    public static function lastCreateLocalName(): ?string
    {
        return self::$pendingCreate[1] ?? null;
    }

    /** Compile-time cache presence check for getAttribute / hasAttribute (#19281, #27108). */
    public static function hasLiteralKey(string $namespace, string $localName): bool
    {
        foreach (self::$byModule as $state) {
            $key = $namespace."\0".$localName;
            if (isset($state['presentByKey'][$key]) || isset($state['slotByKey'][$key])) {
                return true;
            }
        }

        return false;
    }

    /** True when a non-null Attr was stored for this key this module (#27108). */
    public static function hasPresentLiteral(string $namespace, string $localName): bool
    {
        foreach (self::$byModule as $state) {
            if (isset($state['presentByKey'][$namespace."\0".$localName])) {
                return true;
            }
        }

        return false;
    }

    /** True when setIdAttribute* marked this key as ID-bearing (#29884). */
    public static function isIdBearingLiteral(string $namespace, string $localName): bool
    {
        $key = $namespace."\0".$localName;
        foreach (self::$byModule as $state) {
            if (isset($state['idBearingByKey'][$key])) {
                return true;
            }
        }

        return false;
    }

    /** Record / clear ID-bearing flag for setIdAttribute* (#29884). */
    public static function markIdBearingLiteral(string $namespace, string $localName, bool $isId): void
    {
        // Bind to every active module state — setIdAttribute may run before/after Attr store.
        if ([] === self::$byModule) {
            // No module yet: stash pending for first state() call.
            if ($isId) {
                self::$pendingIdBearing[$namespace."\0".$localName] = true;
            } else {
                unset(self::$pendingIdBearing[$namespace."\0".$localName]);
            }

            return;
        }
        $key = $namespace."\0".$localName;
        foreach (self::$byModule as &$state) {
            if ($isId) {
                $state['idBearingByKey'][$key] = true;
            } else {
                unset($state['idBearingByKey'][$key]);
            }
        }
        unset($state);
    }

    /** @var array<string, true> */
    private static array $pendingIdBearing = [];

    public static function literalValue(string $namespace, string $localName): ?string
    {
        $key = $namespace."\0".$localName;
        if (isset(self::$pendingValueByKey[$key])) {
            return self::$pendingValueByKey[$key];
        }
        foreach (self::$byModule as $state) {
            if (isset($state['valueByKey'][$key])) {
                return $state['valueByKey'][$key];
            }
        }

        return null;
    }

    /**
     * Record Attr::$value for createAttribute keys before setAttributeNode/appendChild (#33570).
     *
     * createAttribute only rememberCreate()'s; orphan `$attr->value = …` must seed valueByKey
     * so saveXML open-tag sync sees the literal (peer setAttribute storeLiteral value arg).
     */
    public static function recordLiteralValue(string $namespace, string $localName, string $value): void
    {
        $key = $namespace."\0".$localName;
        self::$pendingValueByKey[$key] = $value;
        if ([] === self::$byModule) {
            return;
        }
        foreach (self::$byModule as &$state) {
            $state['valueByKey'][$key] = $value;
        }
        unset($state);
    }

    /** Seed valueByKey for the pending createAttribute name (#33570). */
    public static function recordPendingCreateValue(string $value): void
    {
        if (null === self::$pendingCreate) {
            return;
        }
        self::recordLiteralValue(self::$pendingCreate[0], self::$pendingCreate[1], $value);
    }

    /** @var array<string, string> */
    private static array $pendingValueByKey = [];

    public static function storeLiteral(
        Context $context,
        string $namespace,
        string $localName,
        Value $attr,
        ?string $value = null
    ): Value {
        $state = &self::state($context);
        if (null !== self::$pendingCreate) {
            $state['lastCreateNamespace'] = self::$pendingCreate[0];
            $state['lastCreateLocalName'] = self::$pendingCreate[1];
        }
        $global = self::slotGlobal($context, $namespace, $localName);
        $prev = $context->builder->load($global);
        $context->builder->store($attr, $global);
        $key = $namespace."\0".$localName;
        $state['presentByKey'][$key] = true;
        if (null !== $value) {
            $state['valueByKey'][$key] = $value;
            unset(self::$pendingValueByKey[$key]);
        } elseif (isset(self::$pendingValueByKey[$key])) {
            $state['valueByKey'][$key] = self::$pendingValueByKey[$key];
            unset(self::$pendingValueByKey[$key]);
        }

        return $prev;
    }

    public static function rekeyLiteral(
        Context $context,
        string $oldNamespace,
        string $oldLocalName,
        string $newNamespace,
        string $newLocalName,
        Value $attr
    ): void {
        $state = &self::state($context);
        $objPtr = $context->getTypeFromString('__object__*');
        $oldGlobal = self::slotGlobal($context, $oldNamespace, $oldLocalName);
        $context->builder->store($objPtr->constNull(), $oldGlobal);
        $oldKey = $oldNamespace."\0".$oldLocalName;
        $value = $state['valueByKey'][$oldKey] ?? null;
        unset($state['presentByKey'][$oldKey], $state['valueByKey'][$oldKey]);
        self::storeLiteral($context, $newNamespace, $newLocalName, $attr, $value);
    }

    public static function clearLiteral(
        Context $context,
        string $namespace,
        string $localName
    ): void {
        $state = &self::state($context);
        $objPtr = $context->getTypeFromString('__object__*');
        $global = self::slotGlobal($context, $namespace, $localName);
        $context->builder->store($objPtr->constNull(), $global);
        unset($state['presentByKey'][$namespace."\0".$localName], $state['valueByKey'][$namespace."\0".$localName]);
    }

    public static function lookupLiteral(
        Context $context,
        string $namespace,
        string $localName
    ): Value {
        $global = self::slotGlobal($context, $namespace, $localName);

        return $context->builder->load($global);
    }

    /**
     * Compile-time (namespace, localName) pairs that already have a module global.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function literalKeys(Context $context): array
    {
        $keys = [];
        foreach (self::state($context)['slotByKey'] as $key => $_) {
            $parts = explode("\0", $key, 2);
            $keys[] = [$parts[0], $parts[1] ?? ''];
        }

        return $keys;
    }

    /** Runtime-only: store null in the Attr slot without compile-time presentByKey fold. */
    public static function nullSlot(Context $context, string $namespace, string $localName): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $global = self::slotGlobal($context, $namespace, $localName);
        $context->builder->store($objPtr->constNull(), $global);
    }

    /** @return array{slotByKey: array<string, Value>, presentByKey: array<string, true>, valueByKey: array<string, string>, idBearingByKey: array<string, true>, lastCreateNamespace: ?string, lastCreateLocalName: ?string} */
    private static function &state(Context $context): array
    {
        $id = spl_object_id($context->module);
        if (!isset(self::$byModule[$id])) {
            self::$byModule[$id] = [
                'slotByKey' => [],
                'presentByKey' => [],
                'valueByKey' => self::$pendingValueByKey,
                'idBearingByKey' => self::$pendingIdBearing,
                'lastCreateNamespace' => null,
                'lastCreateLocalName' => null,
            ];
            self::$pendingIdBearing = [];
            self::$pendingValueByKey = [];
        }

        return self::$byModule[$id];
    }

    private static function slotGlobal(Context $context, string $namespace, string $localName): Value
    {
        $state = &self::state($context);
        $key = $namespace."\0".$localName;
        if (isset($state['slotByKey'][$key])) {
            return $state['slotByKey'][$key];
        }

        $module = $context->module;
        $objPtr = $context->getTypeFromString('__object__*');
        $globalName = '__phpc_dom_us_attr_'.substr(hash('crc32b', $key), 0, 8);
        $existing = $module->getNamedGlobal($globalName);
        if (null !== $existing) {
            $state['slotByKey'][$key] = $existing;

            return $existing;
        }

        $global = $module->addGlobal($objPtr, $globalName);
        $global->setInitializer($objPtr->constNull());
        $state['slotByKey'][$key] = $global;

        return $global;
    }
}
