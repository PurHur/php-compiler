<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\VM\Context;

/**
 * php-src ext/simplexml/sxe.c — zend_class_serialize_deny / unserialize_deny on
 * SimpleXMLElement (inherited by SimpleXMLIterator and user subclasses).
 *
 * @see https://github.com/php/php-src/blob/master/ext/simplexml/sxe.c
 */
final class SimpleXmlSerializeDeny
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
        if (VmSimpleXml::CLASS_LC === $lc || VmSimpleXmlIterator::CLASS_LC === $lc) {
            return true;
        }
        if (null === $ctx) {
            return false;
        }
        $entry = $ctx->classes[$lc] ?? null;
        while (null !== $entry) {
            $nameLc = strtolower($entry->name);
            if (VmSimpleXml::CLASS_LC === $nameLc || VmSimpleXmlIterator::CLASS_LC === $nameLc) {
                return true;
            }
            $parentLc = $entry->parentLc;
            if (null === $parentLc || '' === $parentLc) {
                break;
            }
            // Parent is SimpleXMLElement even when the child entry is not yet walked.
            if (VmSimpleXml::CLASS_LC === $parentLc || VmSimpleXmlIterator::CLASS_LC === $parentLc) {
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
