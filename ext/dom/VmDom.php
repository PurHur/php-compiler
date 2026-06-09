<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
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

    public const PROP_FORMAT_OUTPUT = 'formatOutput';

    public static function registerClasses(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_IMPLEMENTATION])) {
            return;
        }

        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
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
        $document->methods['savexml'] = new DocumentSaveXML();
        $document->methodVisibility['savexml'] = $pub;
        $ctx->classes[self::CLASS_DOCUMENT] = $document;
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

    public static function saveXML(ObjectEntry $document): string
    {
        $state = DomRegistry::state($document);
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

        $root = $state->documentElementName ?? '';
        if ('' !== $root) {
            $lines[] = '<'.self::escapeName($root).'/>';
        }

        return implode("\n", $lines)."\n";
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
            default => $lc,
        };
    }
}
