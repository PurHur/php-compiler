<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

/**
 * php-src @not-serializable DOM XPath objects:
 * - DOMXPath — ext/dom/php_dom.stub.php (#23088)
 * - Dom\XPath — ext/dom/php_dom.stub.php (same @not-serializable flag)
 */
final class DomXPathSerializeDeny
{
    /** @var list<string> */
    private const DENIED_LC = [
        VmDom::CLASS_XPATH,
        VmDomLiving::CLASS_XPATH,
    ];

    public static function rejectSerialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Serialization of '".self::displayName($className)."' is not allowed");
        }
    }

    public static function rejectUnserialization(string $className): void
    {
        if (self::isDenied($className)) {
            throw new \Exception("Unserialization of '".self::displayName($className)."' is not allowed");
        }
    }

    private static function isDenied(string $className): bool
    {
        return \in_array(strtolower(ltrim($className, '\\')), self::DENIED_LC, true);
    }

    private static function displayName(string $className): string
    {
        return ltrim($className, '\\');
    }
}
