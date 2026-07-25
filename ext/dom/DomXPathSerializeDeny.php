<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;

/**
 * php-src @not-serializable DOMXPath / Dom\XPath — ext/dom/xpath.c, php_dom.stub.php (#23088).
 *
 * Short "not allowed" message (no subclass clause); subclasses inherit the deny.
 */
final class DomXPathSerializeDeny
{
    public static function rejectSerialization(string $className, ?Context $ctx = null): void
    {
        if (self::isDenied($className, $ctx)) {
            throw new \Exception("Serialization of '".self::displayName($className)."' is not allowed");
        }
    }

    public static function rejectUnserialization(string $className, ?Context $ctx = null): void
    {
        if (self::isDenied($className, $ctx)) {
            throw new \Exception("Unserialization of '".self::displayName($className)."' is not allowed");
        }
    }

    private static function isDenied(string $className, ?Context $ctx): bool
    {
        $lc = strtolower(ltrim($className, '\\'));
        if (VmDom::CLASS_XPATH === $lc || VmDomLiving::CLASS_XPATH === $lc) {
            return true;
        }
        if (null === $ctx) {
            return false;
        }
        $entry = $ctx->classes[$lc] ?? null;
        while (null !== $entry) {
            $nameLc = strtolower($entry->name);
            if (VmDom::CLASS_XPATH === $nameLc || VmDomLiving::CLASS_XPATH === $nameLc) {
                return true;
            }
            $parentLc = $entry->parentLc;
            if (null === $parentLc || '' === $parentLc) {
                break;
            }
            if (VmDom::CLASS_XPATH === $parentLc || VmDomLiving::CLASS_XPATH === $parentLc) {
                return true;
            }
            $entry = $ctx->classes[$parentLc] ?? null;
        }

        return false;
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
