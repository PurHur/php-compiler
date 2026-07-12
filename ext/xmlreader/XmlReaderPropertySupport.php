<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Virtual XMLReader properties (php-src ext/xmlreader/php_xmlreader.c; #6135).
 */
final class XmlReaderPropertySupport
{
    /** @var list<string> lowercase managed property names */
    private const MANAGED = [
        'attributecount',
        'baseuri',
        'depth',
        'hasattributes',
        'hasvalue',
        'isdefault',
        'isemptyelement',
        'localname',
        'name',
        'namespaceuri',
        'nodetype',
        'prefix',
        'value',
        'xmllang',
    ];

    public static function isManagedProperty(ObjectEntry $object, string $name): bool
    {
        if (VmXmlReader::CLASS_LC !== strtolower($object->class->name)) {
            return false;
        }

        return \in_array(strtolower($name), self::MANAGED, true);
    }

    public static function getProperty(ObjectEntry $object, string $name): Variable
    {
        $var = new Variable();
        $var->objectPropertyOwner = $object;
        $var->objectPropertyName = strtolower($name);
        $lc = strtolower($name);
        $event = VmXmlReader::currentEvent($object);
        $state = XmlReaderRegistry::has($object) ? XmlReaderRegistry::state($object) : null;

        if ('nodetype' === $lc) {
            $var->int(null !== $event ? $event->nodeType : XmlReaderConstants::NONE);

            return $var;
        }
        if ('name' === $lc) {
            $var->string(null !== $event ? $event->name : '');

            return $var;
        }
        if ('value' === $lc) {
            $var->string(null !== $event ? $event->value : '');

            return $var;
        }
        if ('localname' === $lc) {
            $var->string(null !== $event ? $event->localName : '');

            return $var;
        }
        if ('prefix' === $lc) {
            $var->string(null !== $event ? $event->prefix : '');

            return $var;
        }
        if ('namespaceuri' === $lc) {
            $var->string(null !== $event ? $event->namespaceUri : '');

            return $var;
        }
        if ('depth' === $lc) {
            $var->int(null !== $event ? $event->depth : 0);

            return $var;
        }
        if ('attributecount' === $lc) {
            $var->int(null !== $event ? $event->attributeCount : 0);

            return $var;
        }
        if ('hasattributes' === $lc) {
            $var->bool(null !== $event && $event->hasAttributes);

            return $var;
        }
        if ('hasvalue' === $lc) {
            $var->bool(null !== $event && $event->hasValue);

            return $var;
        }
        if ('isemptyelement' === $lc) {
            $var->bool(null !== $event && $event->isEmptyElement);

            return $var;
        }
        if ('isdefault' === $lc) {
            $var->bool(false);

            return $var;
        }
        if ('baseuri' === $lc) {
            $var->string(null !== $state ? $state->uri : '');

            return $var;
        }
        if ('xmllang' === $lc) {
            $var->string('');

            return $var;
        }

        throw new \LogicException('XmlReaderPropertySupport::getProperty() called with unmanaged name');
    }
}
