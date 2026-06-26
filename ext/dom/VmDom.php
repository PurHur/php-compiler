<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DOM factory + serialization in PHP (php-src ext/dom/php_dom.c; issue #6140).
 *
 * PHP-in-PHP: no runtime/*.c growth — tree state in {@see DomRegistry}.
 */
final class VmDom
{
    public const CLASS_IMPLEMENTATION = 'domimplementation';

    public const CLASS_DOCUMENT = 'domdocument';

    public const CLASS_DOCUMENT_TYPE = 'domdocumenttype';

    public const CLASS_ELEMENT = 'domelement';

    public const PROP_FORMAT_OUTPUT = 'formatOutput';

    public const PROP_DOCUMENT_ELEMENT = 'documentElement';

    public const PROP_NODE_NAME = 'nodeName';

    public static function registerClasses(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_IMPLEMENTATION])) {
            return;
        }

        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $strProto = new Variable(Variable::TYPE_STRING);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $objProto = new Variable(Variable::TYPE_OBJECT);
        $pub = CfgFunc::FLAG_PUBLIC;

        $impl = new ClassEntry('DOMImplementation');
        $impl->isInternal = true;
        $impl->methods['createdocument'] = new ImplementationCreateDocument();
        $impl->methodVisibility['createdocument'] = $pub;
        $impl->methods['createdocumenttype'] = new ImplementationCreateDocumentType();
        $impl->methodVisibility['createdocumenttype'] = $pub;
        $impl->methods['hasfeature'] = new ImplementationHasFeature();
        $impl->methodVisibility['hasfeature'] = $pub;
        $ctx->classes[self::CLASS_IMPLEMENTATION] = $impl;

        $doctype = new ClassEntry('DOMDocumentType');
        $doctype->isInternal = true;
        $ctx->classes[self::CLASS_DOCUMENT_TYPE] = $doctype;

        $document = new ClassEntry('DOMDocument');
        $document->isInternal = true;
        $document->properties[] = new ClassProperty(self::PROP_FORMAT_OUTPUT, null, $boolProto);
        $document->properties[] = new ClassProperty(self::PROP_DOCUMENT_ELEMENT, $nullProto, $objProto);
        $document->methods['loadxml'] = new DocumentLoadXML();
        $document->methodVisibility['loadxml'] = $pub;
        $document->methods['createelement'] = new DocumentCreateElement();
        $document->methodVisibility['createelement'] = $pub;
        $document->methods['appendchild'] = new DocumentAppendChild();
        $document->methodVisibility['appendchild'] = $pub;
        $document->methods['savexml'] = new DocumentSaveXML();
        $document->methodVisibility['savexml'] = $pub;
        $ctx->classes[self::CLASS_DOCUMENT] = $document;

        $element = new ClassEntry('DOMElement');
        $element->isInternal = true;
        $element->properties[] = new ClassProperty(self::PROP_NODE_NAME, null, $strProto);
        $element->methods['appendchild'] = new ElementAppendChild();
        $element->methodVisibility['appendchild'] = $pub;
        $ctx->classes[self::CLASS_ELEMENT] = $element;
    }

    public static function createDocumentType(
        Context $ctx,
        string $qualifiedName,
        string $publicId,
        string $systemId
    ): Variable {
        $class = $ctx->classes[self::CLASS_DOCUMENT_TYPE] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMDocumentType is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_DOCUMENT_TYPE_NODE;
        $state->nodeName = $qualifiedName;
        $state->publicId = $publicId;
        $state->systemId = $systemId;
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function createDocument(
        Context $ctx,
        ?string $namespaceUri,
        string $qualifiedName,
        ?ObjectEntry $doctype
    ): Variable {
        if (null !== $doctype && !self::isDocumentType($doctype)) {
            throw new \TypeError(
                'DOMImplementation::createDocument(): Argument #3 ($doctype) must be of type DOMDocumentType or null'
            );
        }

        $class = $ctx->classes[self::CLASS_DOCUMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMDocument is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_FORMAT_OUTPUT)->bool(false);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_DOCUMENT_NODE;
        $state->nodeName = '#document';
        $state->namespaceUri = $namespaceUri;
        $state->documentElementName = $qualifiedName;
        if (null !== $doctype) {
            $dt = DomRegistry::state($doctype);
            $state->doctypeName = $dt->nodeName;
            $state->doctypePublicId = $dt->publicId;
            $state->doctypeSystemId = $dt->systemId;
        }
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function hasFeature(string $feature, string $version): bool
    {
        $feature = strtoupper($feature);
        $version = trim($version);

        return ('XML' === $feature || 'Core' === $feature) && '2.0' === $version;
    }

    public static function ensureDocument(ObjectEntry $document): DomNodeState
    {
        if (!DomRegistry::has($document)) {
            $state = new DomNodeState();
            $state->nodeType = DomConstants::XML_DOCUMENT_NODE;
            $state->nodeName = '#document';
            DomRegistry::attach($document, $state);
            if (!$document->hasProperty(self::PROP_FORMAT_OUTPUT)) {
                $document->getProperty(self::PROP_FORMAT_OUTPUT)->bool(false);
            }
        }

        return DomRegistry::state($document);
    }

    public static function createElement(Context $ctx, string $name): Variable
    {
        $class = $ctx->classes[self::CLASS_ELEMENT] ?? null;
        if (null === $class) {
            throw new \LogicException('DOMElement is not registered in this compiler build');
        }

        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $entry->getProperty(self::PROP_NODE_NAME)->string($name);

        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_ELEMENT_NODE;
        $state->nodeName = $name;
        DomRegistry::attach($entry, $state);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    public static function loadXML(Context $ctx, ObjectEntry $document, string $xml): bool
    {
        self::ensureDocument($document);
        if (!VmXml::isWellFormed($xml)) {
            return false;
        }

        $trimmed = trim($xml);
        $root = self::parseElementTree($ctx, $trimmed);
        if (null === $root) {
            return false;
        }

        $state = DomRegistry::state($document);
        $state->childIds = [];
        $state->documentElementName = DomRegistry::state($root)->nodeName;
        $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->copyFrom(self::elementVariable($root));

        return true;
    }

    public static function appendChild(ObjectEntry $parent, ObjectEntry $child): ObjectEntry
    {
        if (!self::isElement($child)) {
            throw new \DOMException('Hierarchy request error');
        }

        $parentState = DomRegistry::state($parent);
        if (DomConstants::XML_DOCUMENT_NODE === $parentState->nodeType) {
            $existing = $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
            if (Variable::TYPE_NULL !== $existing->type) {
                $parent = $existing->toObject();
                $parentState = DomRegistry::state($parent);
            } else {
                $parentState->documentElementName = DomRegistry::state($child)->nodeName;
                $parent->getProperty(self::PROP_DOCUMENT_ELEMENT)->object($child);

                return $child;
            }
        }

        if (DomConstants::XML_ELEMENT_NODE !== $parentState->nodeType) {
            throw new \DOMException('Hierarchy request error');
        }

        $parentState->childIds[] = $child->id;

        return $child;
    }

    public static function saveXML(ObjectEntry $document): string
    {
        $state = self::ensureDocument($document);
        if (DomConstants::XML_DOCUMENT_NODE !== $state->nodeType) {
            throw new \LogicException('DOMDocument::saveXML() called on non-document node in this compiler build');
        }

        $lines = ['<?xml version="1.0"?>'];

        if (null !== $state->doctypeName) {
            $lines[] = self::serializeDoctype(
                $state->doctypeName,
                $state->doctypePublicId ?? '',
                $state->doctypeSystemId ?? ''
            );
        }

        $rootVar = $document->getProperty(self::PROP_DOCUMENT_ELEMENT)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $rootVar->type) {
            $lines[] = self::serializeElement($rootVar->toObject());
        } elseif (null !== $state->documentElementName && '' !== $state->documentElementName) {
            $lines[] = '<'.self::escapeName($state->documentElementName).'/>';
        }

        return implode("\n", $lines)."\n";
    }

    private static function elementVariable(ObjectEntry $entry): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($entry);

        return $var;
    }

    private static function parseElementTree(Context $ctx, string $elementXml): ?ObjectEntry
    {
        $trimmed = trim($elementXml);
        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed, $selfClose)) {
            return self::createElement($ctx, $selfClose[1])->toObject();
        }
        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/s', $trimmed, $matches)) {
            return null;
        }

        $entry = self::createElement($ctx, $matches[1])->toObject();
        $state = DomRegistry::state($entry);
        $pos = 0;
        $inner = $matches[3];
        $len = \strlen($inner);
        while ($pos < $len) {
            if (preg_match('/\G\s+/s', $inner, $m, 0, $pos)) {
                $pos += \strlen($m[0]);

                continue;
            }
            if ($pos >= $len) {
                break;
            }
            if ('<' !== $inner[$pos]) {
                $next = strpos($inner, '<', $pos);
                $pos = (false === $next) ? $len : $next;

                continue;
            }
            $end = self::findElementEnd($inner, $pos);
            if (null === $end) {
                return null;
            }
            $childXml = substr($inner, $pos, $end - $pos);
            $child = self::parseElementTree($ctx, $childXml);
            if (null === $child) {
                return null;
            }
            $state->childIds[] = $child->id;
            $pos = $end;
        }

        return $entry;
    }

    /** @return null|int byte offset after one element starting at $pos */
    private static function findElementEnd(string $content, int $pos): ?int
    {
        if (!isset($content[$pos]) || '<' !== $content[$pos]) {
            return null;
        }
        if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $selfClose, 0, $pos)) {
            return $pos + \strlen($selfClose[0]);
        }
        if (!preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $open, 0, $pos)) {
            return null;
        }

        /** @var list<string> $stack */
        $stack = [$open[1]];
        $scan = $pos + \strlen($open[0]);
        $len = \strlen($content);
        while ($scan < $len && [] !== $stack) {
            if (preg_match('/\G<\/([A-Za-z_][\w:.-]*)>/s', $content, $close, 0, $scan)) {
                $name = $close[1];
                if ([] === $stack || end($stack) !== $name) {
                    return null;
                }
                array_pop($stack);
                $scan += \strlen($close[0]);
                if ([] === $stack) {
                    return $scan;
                }

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $sc, 0, $scan)) {
                $scan += \strlen($sc[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $nested, 0, $scan)) {
                $stack[] = $nested[1];
                $scan += \strlen($nested[0]);

                continue;
            }
            ++$scan;
        }

        return null;
    }

    private static function serializeElement(ObjectEntry $entry): string
    {
        $state = DomRegistry::state($entry);
        $name = self::escapeName($state->nodeName);
        if ([] === $state->childIds) {
            return '<'.$name.'/>';
        }
        $parts = [];
        foreach ($state->childIds as $childId) {
            $child = DomRegistry::entry($childId);
            if (null !== $child) {
                $parts[] = self::serializeElement($child);
            }
        }

        return '<'.$name.'>'.implode('', $parts).'</'.$name.'>';
    }

    public static function isElement(ObjectEntry $entry): bool
    {
        return self::CLASS_ELEMENT === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_ELEMENT_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function isDocument(ObjectEntry $entry): bool
    {
        return self::CLASS_DOCUMENT === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_DOCUMENT_NODE === DomRegistry::state($entry)->nodeType;
    }

    private static function serializeDoctype(string $name, string $publicId, string $systemId): string
    {
        if ('' !== $publicId || '' !== $systemId) {
            return sprintf(
                '<!DOCTYPE %s PUBLIC "%s" "%s">',
                self::escapeName($name),
                self::escapeAttr($publicId),
                self::escapeAttr($systemId)
            );
        }

        return '<!DOCTYPE '.self::escapeName($name).'>';
    }

    private static function escapeAttr(string $value): string
    {
        return str_replace(['&', '"'], ['&amp;', '&quot;'], $value);
    }

    private static function escapeName(string $name): string
    {
        return $name;
    }

    public static function requireReceiver(Variable $var, string $classLc, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s must be called on an object, %s given',
                $label,
                self::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if ($classLc !== strtolower($object->class->name)) {
            throw new \TypeError(sprintf('%s must be called on a %s instance', $label, self::classNameFromLc($classLc)));
        }

        return $object;
    }

    public static function isDocumentType(ObjectEntry $entry): bool
    {
        return self::CLASS_DOCUMENT_TYPE === strtolower($entry->class->name)
            && DomRegistry::has($entry)
            && DomConstants::XML_DOCUMENT_TYPE_NODE === DomRegistry::state($entry)->nodeType;
    }

    public static function typeLabel(Variable $var): string
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return EnumCaseSupport::typeNameForVariable($var);
        }

        return match ($var->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_FLOAT => 'float',
            default => 'mixed',
        };
    }

    private static function classNameFromLc(string $lc): string
    {
        return match ($lc) {
            self::CLASS_IMPLEMENTATION => 'DOMImplementation',
            self::CLASS_DOCUMENT => 'DOMDocument',
            self::CLASS_DOCUMENT_TYPE => 'DOMDocumentType',
            self::CLASS_ELEMENT => 'DOMElement',
            default => $lc,
        };
    }
}
