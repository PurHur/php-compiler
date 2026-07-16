<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * SimpleXML tree + OOP API (php-src ext/simplexml/simplexml.c; #3338).
 */
final class VmSimpleXml
{
    public const CLASS_LC = 'simplexmlelement';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('SimpleXMLElement');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        if (isset($ctx->classes['arrayaccess'])) {
            $entry->interfaces[] = 'arrayaccess';
        }
        SimpleXmlElementIterator::registerInterfaces($entry, $ctx);

        $entry->constructor = new SimpleXmlElementConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['__get'] = new SimpleXmlElementGet();
        $entry->methodVisibility['__get'] = $pub;
        $entry->methods['__tostring'] = new SimpleXmlElementToString();
        $entry->methodVisibility['__tostring'] = $pub;
        $entry->methodNames['__tostring'] = '__toString';
        $entry->methods['offsetget'] = new SimpleXmlElementOffsetGet();
        $entry->methodVisibility['offsetget'] = $pub;
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methods['offsetexists'] = new SimpleXmlElementOffsetExists();
        $entry->methodVisibility['offsetexists'] = $pub;
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methods['offsetset'] = new SimpleXmlElementOffsetSet();
        $entry->methodVisibility['offsetset'] = $pub;
        $entry->methodNames['offsetset'] = 'offsetSet';
        $entry->methods['offsetunset'] = new SimpleXmlElementOffsetUnset();
        $entry->methodVisibility['offsetunset'] = $pub;
        $entry->methodNames['offsetunset'] = 'offsetUnset';
        $entry->methods['count'] = new SimpleXmlElementCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methods['getname'] = new SimpleXmlElementGetName();
        $entry->methodVisibility['getname'] = $pub;
        $entry->methodNames['getname'] = 'getName';
        $entry->methods['children'] = new SimpleXmlElementChildren();
        $entry->methodVisibility['children'] = $pub;
        $entry->methods['asxml'] = new SimpleXmlElementAsXml();
        $entry->methodVisibility['asxml'] = $pub;
        $entry->methodNames['asxml'] = 'asXML';
        $entry->methods['addchild'] = new SimpleXmlElementAddChild();
        $entry->methodVisibility['addchild'] = $pub;
        $entry->methodNames['addchild'] = 'addChild';
        $entry->methods['addattribute'] = new SimpleXmlElementAddAttribute();
        $entry->methodVisibility['addattribute'] = $pub;
        $entry->methodNames['addattribute'] = 'addAttribute';
        $entry->methods['xpath'] = new SimpleXmlElementXpath();

        $entry->methodVisibility['xpath'] = $pub;
        $entry->methods['attributes'] = new SimpleXmlElementAttributes();
        $entry->methodVisibility['attributes'] = $pub;
        $entry->methods['getdocnamespaces'] = new SimpleXmlElementGetDocNamespaces();
        $entry->methodVisibility['getdocnamespaces'] = $pub;
        $entry->methodNames['getdocnamespaces'] = 'getDocNamespaces';
        $entry->methods['getnamespaces'] = new SimpleXmlElementGetNamespaces();
        $entry->methodVisibility['getnamespaces'] = $pub;
        $entry->methodNames['getnamespaces'] = 'getNamespaces';
        $entry->methods['registerxpathnamespace'] = new SimpleXmlElementRegisterXPathNamespace();
        $entry->methodVisibility['registerxpathnamespace'] = $pub;
        $entry->methodNames['registerxpathnamespace'] = 'registerXPathNamespace';
        SimpleXmlElementIterator::registerMethods($entry, $pub);

        $ctx->classes[self::CLASS_LC] = $entry;
        $ctx->classes[self::CLASS_LC]->isInternal = true;
    }

    public static function loadString(Context $ctx, string $data, ?Frame $frame = null): ?ObjectEntry
    {
        $trimmed = trim($data);
        if ('' === $trimmed) {
            self::warn($ctx, 'simplexml_load_string(): supplied argument cannot be empty', $frame);

            return null;
        }

        if (!VmXml::validateAndReport($ctx, $trimmed, $frame)) {
            return null;
        }

        $root = self::parseDocumentRoot($trimmed);
        if (null === $root) {
            self::warn($ctx, 'simplexml_load_string(): Entity: line 1: parser error', $frame);

            return null;
        }

        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('SimpleXMLElement is not registered in this compiler build');
        }

        return self::wrapNode($ctx, $class, $root);
    }

    /**
     * SimpleXMLElement::__construct — attach parsed root onto $this
     * (php-src zim_simplexmlelement___construct; #19307 / #19306).
     */
    public static function constructFromData(
        Context $ctx,
        ObjectEntry $entry,
        string $data,
        ?Frame $frame = null
    ): void {
        $trimmed = trim($data);
        if ('' === $trimmed) {
            throw new \Exception('String could not be parsed as XML');
        }

        if (!VmXml::validateAndReport($ctx, $trimmed, $frame)) {
            throw new \Exception('String could not be parsed as XML');
        }

        $root = self::parseDocumentRoot($trimmed);
        if (null === $root) {
            self::warn($ctx, 'SimpleXMLElement::__construct(): Entity: line 1: parser error', $frame);

            throw new \Exception('String could not be parsed as XML');
        }

        SimpleXmlRegistry::attach($entry, $root, $entry->id);
        $entry->constructed = true;
    }

    public static function loadFile(Context $ctx, string $filename, ?Frame $frame = null): ?ObjectEntry
    {
        if ('' === $filename) {
            self::warn($ctx, 'simplexml_load_file(): failed to load external entity ""', $frame);

            return null;
        }
        $contents = VmFsReadNative::read($filename);
        if (false === $contents) {
            self::warn($ctx, 'simplexml_load_file(): Failed to open stream: No such file or directory', $frame);

            return null;
        }

        return self::loadString($ctx, $contents, $frame);
    }

    public static function requireElement(ObjectEntry $entry, string $label): ObjectEntry
    {
        if (self::CLASS_LC !== strtolower($entry->class->name)) {
            throw new \TypeError(sprintf('%s(): Argument must be SimpleXMLElement, %s given', $label, $entry->class->name));
        }
        if (!SimpleXmlRegistry::has($entry)) {
            throw new \LogicException($label.'(): SimpleXMLElement has no node state');
        }

        return $entry;
    }

    public static function childByName(Context $ctx, ObjectEntry $entry, string $name): ObjectEntry
    {
        $docKey = SimpleXmlRegistry::documentKey($entry);
        $elements = self::matchingElements($entry, $name);
        if ([] === $elements) {
            return self::wrapView($ctx, $entry->class, [], $docKey);
        }
        if (1 === \count($elements)) {
            return self::wrapNode($ctx, $entry->class, $elements[0], $docKey);
        }

        return self::wrapView($ctx, $entry->class, $elements, $docKey);
    }

    public static function offsetGet(Context $ctx, ObjectEntry $entry, Variable $offset): Variable
    {
        $offset = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER === $offset->type) {
            $index = $offset->toInt();
            if (SimpleXmlRegistry::isAttributesView($entry)) {
                $values = array_values(SimpleXmlRegistry::state($entry)->attributes);
                if ($index < 0 || $index >= \count($values)) {
                    $result = new Variable();
                    $result->null();

                    return $result;
                }
                $result = new Variable();
                $result->string($values[$index]);

                return $result;
            }
            $elements = SimpleXmlRegistry::view($entry);
            if ($index < 0 || $index >= \count($elements)) {
                $result = new Variable();
                $result->null();

                return $result;
            }

            $result = new Variable();
            $result->object(self::wrapNode($ctx, $entry->class, $elements[$index], SimpleXmlRegistry::documentKey($entry)));

            return $result;
        }
        if (Variable::TYPE_STRING === $offset->type) {
            $name = $offset->toString();
            $state = SimpleXmlRegistry::state($entry);
            $value = $state->attributes[$name] ?? null;
            $result = new Variable();
            if (null === $value) {
                $result->null();
            } else {
                $result->string($value);
            }

            return $result;
        }

        throw new \TypeError('SimpleXMLElement::offsetGet(): Argument #1 ($offset) must be of type int|string');
    }

    public static function offsetExists(ObjectEntry $entry, Variable $offset): bool
    {
        $offset = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER === $offset->type) {
            $index = $offset->toInt();
            if (SimpleXmlRegistry::isAttributesView($entry)) {
                $values = array_values(SimpleXmlRegistry::state($entry)->attributes);

                return $index >= 0 && $index < \count($values);
            }
            $elements = SimpleXmlRegistry::view($entry);

            return $index >= 0 && $index < \count($elements);
        }
        if (Variable::TYPE_STRING === $offset->type) {
            $state = SimpleXmlRegistry::state($entry);

            return \array_key_exists($offset->toString(), $state->attributes);
        }

        return false;
    }

    /**
     * SimpleXMLElement::offsetSet (php-src ext/simplexml/sxe.c sxe_prop_dim_write; #19536).
     *
     * Integer offsets write element text (or attribute value by index on an attributes view).
     * Non-integer offsets coerce to attribute names (overwrite allowed; empty name → ValueError).
     */
    public static function offsetSet(
        Context $ctx,
        ObjectEntry $entry,
        Variable $offset,
        Variable $value,
        ?Frame $frame = null
    ): void {
        $offset = $offset->resolveIndirect();
        $value = $value->resolveIndirect();
        $vm = $frame?->vmContext?->vm ?? null;

        if (Variable::TYPE_INTEGER === $offset->type) {
            $index = $offset->toInt();
            $stringValue = $value->toString($vm, $frame);
            if (SimpleXmlRegistry::isAttributesView($entry)) {
                $state = SimpleXmlRegistry::state($entry);
                $keys = array_keys($state->attributes);
                if ($index < 0 || $index >= \count($keys)) {
                    self::warn($ctx, 'SimpleXMLElement::offsetSet(): Cannot add attribute number '.$index.' when only '.\count($keys).' such attributes exist', $frame);

                    return;
                }
                $state->attributes[$keys[$index]] = $stringValue;

                return;
            }
            $elements = SimpleXmlRegistry::view($entry);
            if ($index < 0 || $index >= \count($elements)) {
                $label = ([] !== $elements) ? $elements[0]->name : self::elementName($entry);
                self::warn(
                    $ctx,
                    'SimpleXMLElement::offsetSet(): Cannot add element '.$label.' number '.$index
                    .' when only '.\count($elements).' such elements exist',
                    $frame
                );

                return;
            }
            $node = $elements[$index];
            $node->children = [];
            $node->text = $stringValue;

            return;
        }

        $name = $offset->toString($vm, $frame);
        if ('' === $name) {
            throw new \ValueError('Cannot create attribute with an empty name');
        }

        if (SimpleXmlRegistry::isAttributesView($entry)) {
            $state = SimpleXmlRegistry::state($entry);
            if (!\array_key_exists($name, $state->attributes)) {
                // php-src: attributes() view cannot introduce new attributes.
                return;
            }
            $state->attributes[$name] = $value->toString($vm, $frame);

            return;
        }

        if (SimpleXmlRegistry::isView($entry)) {
            $elements = SimpleXmlRegistry::view($entry);
            if ([] === $elements) {
                return;
            }
            // php-src: dimension write on a multi-node selection updates the first node.
            $elements[0]->attributes[$name] = $value->toString($vm, $frame);

            return;
        }

        SimpleXmlRegistry::state($entry)->attributes[$name] = $value->toString($vm, $frame);
    }

    /**
     * SimpleXMLElement::offsetUnset (php-src ext/simplexml/sxe.c sxe_prop_dim_delete; #19536).
     */
    public static function offsetUnset(
        Context $ctx,
        ObjectEntry $entry,
        Variable $offset,
        ?Frame $frame = null
    ): void {
        $offset = $offset->resolveIndirect();
        $vm = $frame?->vmContext?->vm ?? null;

        if (Variable::TYPE_INTEGER === $offset->type) {
            $index = $offset->toInt();
            if (SimpleXmlRegistry::isAttributesView($entry)) {
                $state = SimpleXmlRegistry::state($entry);
                $keys = array_keys($state->attributes);
                if ($index >= 0 && $index < \count($keys)) {
                    unset($state->attributes[$keys[$index]]);
                }

                return;
            }
            // Full element deletion needs a parent pointer; clear node content like a soft unset
            // of the selected node's payload (attribute unset is the ArrayAccess write companion).
            $elements = SimpleXmlRegistry::view($entry);
            if ($index >= 0 && $index < \count($elements)) {
                $node = $elements[$index];
                $node->attributes = [];
                $node->children = [];
                $node->text = '';
            }

            return;
        }

        $name = $offset->toString($vm, $frame);
        if ('' === $name) {
            return;
        }
        $state = SimpleXmlRegistry::state($entry);
        unset($state->attributes[$name]);
    }

    public static function countElements(ObjectEntry $entry): int
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return \count(SimpleXmlRegistry::state($entry)->attributes);
        }
        if (SimpleXmlRegistry::isView($entry)) {
            return \count(SimpleXmlRegistry::view($entry));
        }

        return \count(SimpleXmlRegistry::state($entry)->children);
    }

    public static function elementName(ObjectEntry $entry): string
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            $attrs = SimpleXmlRegistry::state($entry)->attributes;
            if ([] === $attrs) {
                return '';
            }
            /** @var string $first */
            $first = array_key_first($attrs);

            return $first;
        }
        if (SimpleXmlRegistry::isView($entry)) {
            $view = SimpleXmlRegistry::view($entry);
            if ([] === $view) {
                return '';
            }

            return $view[0]->name;
        }

        return SimpleXmlRegistry::state($entry)->name;
    }

    public static function children(Context $ctx, ObjectEntry $entry, ?string $namespaceOrPrefix = null, bool $isPrefix = true): ObjectEntry
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return self::wrapView($ctx, $entry->class, [], SimpleXmlRegistry::documentKey($entry));
        }

        $elements = self::directElementChildren($entry);
        if (null !== $namespaceOrPrefix && '' !== $namespaceOrPrefix) {
            $elements = self::filterChildrenByNamespace($elements, $namespaceOrPrefix, $isPrefix, $entry);
        }

        return self::wrapView($ctx, $entry->class, $elements, SimpleXmlRegistry::documentKey($entry));
    }

    public static function attributes(Context $ctx, ObjectEntry $entry, ?string $namespaceOrPrefix = null, bool $isPrefix = true): ObjectEntry
    {
        if (SimpleXmlRegistry::isView($entry) || SimpleXmlRegistry::isAttributesView($entry)) {
            return self::wrapAttributesView($ctx, $entry->class, new SimpleXmlNodeState(''), SimpleXmlRegistry::documentKey($entry));
        }

        $state = SimpleXmlRegistry::state($entry);
        if (null !== $namespaceOrPrefix && '' !== $namespaceOrPrefix) {
            $filtered = self::filterAttributesByNamespace($state->attributes, $namespaceOrPrefix, $isPrefix, $entry);
            $viewState = new SimpleXmlNodeState($state->name, $filtered);

            return self::wrapAttributesView($ctx, $entry->class, $viewState, SimpleXmlRegistry::documentKey($entry));
        }

        return self::wrapAttributesView($ctx, $entry->class, $state, SimpleXmlRegistry::documentKey($entry));
    }

    public static function asXml(ObjectEntry $entry, bool $includeDeclaration = false): string|false
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return false;
        }
        if (SimpleXmlRegistry::isView($entry)) {
            $parts = [];
            foreach (SimpleXmlRegistry::view($entry) as $node) {
                $parts[] = self::serializeNode($node);
            }
            $body = implode('', $parts);
            if ('' === $body) {
                return false;
            }

            return $includeDeclaration ? '<?xml version="1.0"?>'."\n".$body : $body;
        }

        $state = SimpleXmlRegistry::state($entry);
        $body = self::serializeNode($state);

        return $includeDeclaration ? '<?xml version="1.0"?>'."\n".$body : $body;
    }

    public static function addChild(
        Context $ctx,
        ObjectEntry $entry,
        string $qualifiedName,
        ?string $value = null,
        ?string $namespace = null
    ): ObjectEntry {
        if ('' === $qualifiedName) {
            throw new \ValueError('SimpleXMLElement::addChild(): Argument #1 ($qualifiedName) cannot be empty');
        }
        if (SimpleXmlRegistry::isView($entry) || SimpleXmlRegistry::isAttributesView($entry)) {
            throw new \LogicException('SimpleXMLElement::addChild() cannot be called on a view in this compiler build');
        }

        $child = new SimpleXmlNodeState($qualifiedName);
        if (null !== $value && '' !== $value) {
            $child->text = $value;
        }
        SimpleXmlRegistry::state($entry)->children[] = $child;

        return self::wrapNode($ctx, $entry->class, $child, SimpleXmlRegistry::documentKey($entry));
    }

    /**
     * SimpleXMLElement::addAttribute (php-src ext/simplexml/sxe.c zim_simplexmlelement_addAttribute; #19307).
     */
    public static function addAttribute(
        Context $ctx,
        ObjectEntry $entry,
        string $qualifiedName,
        string $value,
        ?string $namespace = null,
        ?Frame $frame = null
    ): void {
        if ('' === $qualifiedName) {
            throw new \ValueError('SimpleXMLElement::addAttribute(): Argument #1 ($qualifiedName) cannot be empty');
        }
        if (SimpleXmlRegistry::isView($entry) && !SimpleXmlRegistry::isAttributesView($entry)) {
            throw new \LogicException('SimpleXMLElement::addAttribute() cannot be called on a children view in this compiler build');
        }

        $state = SimpleXmlRegistry::state($entry);

        if (\array_key_exists($qualifiedName, $state->attributes)) {
            self::warn($ctx, 'SimpleXMLElement::addAttribute(): Attribute already exists', $frame);

            return;
        }

        if (null !== $namespace && '' !== $namespace) {
            $colon = strpos($qualifiedName, ':');
            if (false !== $colon) {
                $prefix = substr($qualifiedName, 0, $colon);
                if ('' !== $prefix) {
                    $xmlnsKey = 'xmlns:'.$prefix;
                    if (!\array_key_exists($xmlnsKey, $state->attributes)) {
                        $state->attributes[$xmlnsKey] = $namespace;
                    }
                }
            }
        }

        $state->attributes[$qualifiedName] = $value;
    }

    /** @return HashTable list of SimpleXMLElement objects */
    public static function xpath(Context $ctx, ObjectEntry $entry, string $expression): HashTable
    {
        $expression = trim($expression);
        $ht = new HashTable();
        if ('' === $expression) {
            return $ht;
        }

        $contextNodes = self::xpathContextNodes($entry);
        if (preg_match('~^//([\w:-]+)(?:\[@([\w:-]+)=["\']([^"\']*)["\']\])?$~', $expression, $m)) {
            foreach ($contextNodes as $context) {
                foreach (self::collectDescendantsNamed($context, $m[1]) as $node) {
                    if (isset($m[2]) && (!\array_key_exists($m[2], $node->attributes) || $node->attributes[$m[2]] !== $m[3])) {
                        continue;
                    }
                    $var = new Variable();
                    $var->object(self::wrapNode($ctx, $entry->class, $node, SimpleXmlRegistry::documentKey($entry)));
                    $ht->append($var);
                }
            }

            return $ht;
        }
        if ('.' === $expression) {
            foreach ($contextNodes as $context) {
                $var = new Variable();
                $var->object(self::wrapNode($ctx, $entry->class, $context, SimpleXmlRegistry::documentKey($entry)));
                $ht->append($var);
            }
        }

        return $ht;
    }

    /** @return HashTable string map */
    public static function getNamespaces(ObjectEntry $entry, bool $recursive = false): HashTable
    {
        return self::stringMapToHashTable(self::collectNamespaces($entry, $recursive, false));
    }

    /** @return HashTable string map */
    public static function getDocNamespaces(ObjectEntry $entry, bool $recursive = false, bool $fromRoot = true): HashTable
    {
        return self::stringMapToHashTable(self::collectNamespaces($entry, $recursive, $fromRoot));
    }

    public static function registerXPathNamespace(ObjectEntry $entry, string $prefix, string $namespaceUri): bool
    {
        return SimpleXmlRegistry::registerXPathNamespace($entry, $prefix, $namespaceUri);
    }

    /** @return list<SimpleXmlNodeState> */
    public static function directElementChildren(ObjectEntry $entry): array
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return [];
        }
        if (SimpleXmlRegistry::isView($entry)) {
            return SimpleXmlRegistry::view($entry);
        }

        return SimpleXmlRegistry::state($entry)->children;
    }

    public static function wrapIteratorNode(
        Context $ctx,
        ClassEntry $class,
        SimpleXmlNodeState $node,
        ?int $documentKey = null
    ): ObjectEntry {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $docKey = $documentKey ?? $entry->id;
        SimpleXmlRegistry::attach($entry, $node, $docKey);
        SimpleXmlIteratorStorage::init($entry);

        return $entry;
    }

    public static function textContent(ObjectEntry $entry): string
    {
        if (SimpleXmlRegistry::isView($entry)) {
            $parts = [];
            foreach (SimpleXmlRegistry::view($entry) as $node) {
                $parts[] = $node->text;
            }

            return implode('', $parts);
        }

        return SimpleXmlRegistry::state($entry)->text;
    }

    /** @return list<SimpleXmlNodeState> */
    private static function matchingElements(ObjectEntry $entry, string $name): array
    {
        if (SimpleXmlRegistry::isView($entry)) {
            $out = [];
            foreach (SimpleXmlRegistry::view($entry) as $node) {
                if ($node->name === $name) {
                    $out[] = $node;
                }
            }

            return $out;
        }

        return SimpleXmlRegistry::state($entry)->elementsNamed($name);
    }

    private static function wrapNode(Context $ctx, ClassEntry $class, SimpleXmlNodeState $node, ?int $documentKey = null): ObjectEntry
    {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $docKey = $documentKey ?? $entry->id;
        SimpleXmlRegistry::attach($entry, $node, $docKey);

        return $entry;
    }

    /** @param list<SimpleXmlNodeState> $elements */
    private static function wrapView(Context $ctx, ClassEntry $class, array $elements, ?int $documentKey = null): ObjectEntry
    {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $docKey = $documentKey ?? $entry->id;
        if ([] === $elements) {
            SimpleXmlRegistry::attach($entry, new SimpleXmlNodeState(''), $docKey);
            SimpleXmlRegistry::attachView($entry, [], $docKey);
        } else {
            SimpleXmlRegistry::attach($entry, $elements[0], $docKey);
            SimpleXmlRegistry::attachView($entry, $elements, $docKey);
        }

        return $entry;
    }

    private static function wrapAttributesView(
        Context $ctx,
        ClassEntry $class,
        SimpleXmlNodeState $state,
        ?int $documentKey = null
    ): ObjectEntry {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $docKey = $documentKey ?? $entry->id;
        SimpleXmlRegistry::attachAttributesView($entry, $state, $docKey);

        return $entry;
    }

    private static function serializeNode(SimpleXmlNodeState $node): string
    {
        $attrs = '';
        foreach ($node->attributes as $name => $value) {
            $attrs .= sprintf(' %s="%s"', $name, self::escapeXmlAttribute($value));
        }
        if ([] === $node->children && '' === $node->text) {
            return sprintf('<%s%s/>', $node->name, $attrs);
        }
        $inner = self::escapeXmlText($node->text);
        foreach ($node->children as $child) {
            $inner .= self::serializeNode($child);
        }

        return sprintf('<%s%s>%s</%s>', $node->name, $attrs, $inner, $node->name);
    }

    private static function escapeXmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function escapeXmlText(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    /** @return list<SimpleXmlNodeState> */
    private static function xpathContextNodes(ObjectEntry $entry): array
    {
        if (SimpleXmlRegistry::isView($entry)) {
            return SimpleXmlRegistry::view($entry);
        }
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return [];
        }

        return [SimpleXmlRegistry::state($entry)];
    }

    /** @return list<SimpleXmlNodeState> */
    private static function collectDescendantsNamed(SimpleXmlNodeState $node, string $name): array
    {
        $out = [];
        if ('*' !== $name && $node->name === $name) {
            $out[] = $node;
        } elseif ('*' === $name) {
            $out[] = $node;
        }
        foreach ($node->children as $child) {
            foreach (self::collectDescendantsNamed($child, $name) as $match) {
                $out[] = $match;
            }
        }

        return $out;
    }

    /** @param list<SimpleXmlNodeState> $elements */
    private static function filterChildrenByNamespace(
        array $elements,
        string $namespaceOrPrefix,
        bool $isPrefix,
        ObjectEntry $entry
    ): array {
        $namespaces = SimpleXmlRegistry::xpathNamespaces($entry);
        $targetUri = $isPrefix ? ($namespaces[$namespaceOrPrefix] ?? $namespaceOrPrefix) : $namespaceOrPrefix;
        $out = [];
        foreach ($elements as $element) {
            $elementUri = $element->attributes['xmlns'] ?? ($element->attributes['xmlns:'.$namespaceOrPrefix] ?? '');
            if ($isPrefix) {
                if (($namespaces[$namespaceOrPrefix] ?? null) === $targetUri || $elementUri === $targetUri) {
                    $out[] = $element;
                }
            } elseif ($elementUri === $targetUri) {
                $out[] = $element;
            }
        }

        return $out;
    }

    /** @param array<string, string> $attributes */
    private static function filterAttributesByNamespace(
        array $attributes,
        string $namespaceOrPrefix,
        bool $isPrefix,
        ObjectEntry $entry
    ): array {
        $namespaces = SimpleXmlRegistry::xpathNamespaces($entry);
        if ($isPrefix && !isset($namespaces[$namespaceOrPrefix])) {
            return [];
        }
        $prefix = $isPrefix ? $namespaceOrPrefix.':' : '';

        $out = [];
        foreach ($attributes as $name => $value) {
            if ($isPrefix) {
                if (str_starts_with($name, $prefix)) {
                    $out[$name] = $value;
                }
            } elseif (str_starts_with($name, $namespaceOrPrefix.':')) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, string> $map */
    private static function stringMapToHashTable(array $map): HashTable
    {
        $ht = new HashTable();
        foreach ($map as $key => $value) {
            $var = new Variable();
            $var->string($value);
            $ht->update($key, $var);
        }

        return $ht;
    }

    /** @return array<string, string> */
    private static function collectNamespaces(ObjectEntry $entry, bool $recursive, bool $fromRoot): array
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return [];
        }

        $nodes = [];
        if (SimpleXmlRegistry::isView($entry)) {
            $nodes = SimpleXmlRegistry::view($entry);
        } else {
            $nodes = [SimpleXmlRegistry::state($entry)];
        }

        $out = [];
        foreach ($nodes as $node) {
            self::collectNamespacesFromNode($node, $out, $recursive);
        }

        return $out;
    }

    /** @param array<string, string> $out */
    private static function collectNamespacesFromNode(SimpleXmlNodeState $node, array &$out, bool $recursive): void
    {
        foreach ($node->attributes as $name => $value) {
            if ('xmlns' === $name) {
                $out[''] = $value;
            } elseif (str_starts_with($name, 'xmlns:')) {
                $out[substr($name, 6)] = $value;
            }
        }
        if ($recursive) {
            foreach ($node->children as $child) {
                self::collectNamespacesFromNode($child, $out, true);
            }
        }
    }

    private static function parseDocumentRoot(string $xml): ?SimpleXmlNodeState
    {
        $elementXml = preg_replace('/<\?xml[^?]*\?>/', '', $xml) ?? $xml;
        $elementXml = trim($elementXml);

        return self::parseElementTree(trim($elementXml));
    }

    private static function parseElementTree(string $elementXml): ?SimpleXmlNodeState
    {
        $trimmed = trim($elementXml);
        if (preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>$/s', $trimmed, $selfClose)) {
            return new SimpleXmlNodeState(
                $selfClose[1],
                self::parseAttributes($selfClose[2] ?? ''),
            );
        }
        if (!preg_match('/^<([A-Za-z_][\w:.-]*)(\s[^>]*)?>(.*)<\/\1>\s*$/s', $trimmed, $matches)) {
            return null;
        }

        $node = new SimpleXmlNodeState($matches[1], self::parseAttributes($matches[2] ?? ''));
        $inner = $matches[3];
        $pos = 0;
        $len = \strlen($inner);
        $textBuffer = '';
        while ($pos < $len) {
            if ('<' !== $inner[$pos]) {
                $next = strpos($inner, '<', $pos);
                $chunk = false === $next ? substr($inner, $pos) : substr($inner, $pos, $next - $pos);
                $textBuffer .= $chunk;
                $pos = false === $next ? $len : $next;

                continue;
            }
            if ('' !== $textBuffer) {
                $node->text .= $textBuffer;
                $textBuffer = '';
            }
            $end = self::findElementEnd($inner, $pos);
            if (null === $end) {
                return null;
            }
            $childXml = substr($inner, $pos, $end - $pos);
            $child = self::parseElementTree($childXml);
            if (null === $child) {
                return null;
            }
            $node->children[] = $child;
            $pos = $end;
        }
        $node->text .= $textBuffer;

        return $node;
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
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?\/>/s', $content, $selfClose, 0, $scan)) {
                $scan += \strlen($selfClose[0]);

                continue;
            }
            if (preg_match('/\G<([A-Za-z_][\w:.-]*)(\s[^>]*)?>/s', $content, $childOpen, 0, $scan)) {
                $stack[] = $childOpen[1];
                $scan += \strlen($childOpen[0]);

                continue;
            }

            ++$scan;
        }

        return null;
    }

    /** @return array<string, string> */
    private static function parseAttributes(string $attrString): array
    {
        $attrs = [];
        if ('' === trim($attrString)) {
            return $attrs;
        }
        if (preg_match_all('/([A-Za-z_][\w:.-]*)\s*=\s*(\"[^\"]*\"|\'[^\']*\')/', $attrString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = substr($match[2], 1, -1);
                $attrs[$match[1]] = $value;
            }
        }

        return $attrs;
    }

    private static function warn(Context $ctx, string $message, ?Frame $frame): void
    {
        if (null !== $frame && null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                $message,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }
    }
}
