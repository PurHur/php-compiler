<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\dom\VmDomSimpleXmlBridge;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\xml\VmXml;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ResourceSupport;
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
        $entry->methods['__set'] = new SimpleXmlElementSet();
        $entry->methodVisibility['__set'] = $pub;
        $entry->methods['__isset'] = new SimpleXmlElementIsset();
        $entry->methodVisibility['__isset'] = $pub;
        $entry->methods['__unset'] = new SimpleXmlElementUnset();
        $entry->methodVisibility['__unset'] = $pub;
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
        $entry->methods['asxml'] = new SimpleXmlElementAsXml('asXML');
        $entry->methodVisibility['asxml'] = $pub;
        $entry->methodNames['asxml'] = 'asXML';
        // php-src FALIAS: saveXML → asXML (ext/simplexml/sxe.c / simplexml.stub.php; #19413).
        $entry->methods['savexml'] = new SimpleXmlElementAsXml('saveXML');
        $entry->methodVisibility['savexml'] = $pub;
        $entry->methodNames['savexml'] = 'saveXML';
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

    /**
     * Resolve class_name for simplexml_load_* / construct (php-src sxe_object_new; #22406).
     *
     * @throws \TypeError when class is missing or not derived from SimpleXMLElement
     */
    public static function resolveClass(Context $ctx, ?string $className, string $func): ClassEntry
    {
        $className = null === $className || '' === $className ? 'SimpleXMLElement' : $className;
        $class = $ctx->classes[strtolower($className)] ?? null;
        if (null === $class
            || (self::CLASS_LC !== strtolower($class->name)
                && self::CLASS_LC !== strtolower($class->parentLc ?? ''))) {
            throw new \TypeError(sprintf(
                '%s(): Argument #2 ($class_name) must be a class name derived from SimpleXMLElement or null, %s given',
                $func,
                $className
            ));
        }

        return $class;
    }

    public static function loadString(
        Context $ctx,
        string $data,
        ?Frame $frame = null,
        ?ClassEntry $class = null
    ): ?ObjectEntry {
        $trimmed = trim($data);
        // php-src: empty/whitespace-only after Z_PARAM_STR coerce → false with no warning
        // (Zend 8.2+/8.4; null→'' soft path for #21502). Whitespace that is not empty still
        // goes through libxml and may emit parser errors.
        if ('' === $trimmed) {
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

        if (null === $class) {
            $class = $ctx->classes[self::CLASS_LC] ?? null;
        }
        if (null === $class) {
            throw new \LogicException('SimpleXMLElement is not registered in this compiler build');
        }

        $entry = self::wrapNode($ctx, $class, $root);
        // php-src: iter.data UNDEF until rewind — mark uninitialized for SXE / SimpleXMLIterator (#22406).
        SimpleXmlIteratorStorage::init($entry);

        return $entry;
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
        SimpleXmlIteratorStorage::init($entry);
    }

    public static function loadFile(
        Context $ctx,
        string $filename,
        ?Frame $frame = null,
        ?ClassEntry $class = null
    ): ?ObjectEntry {
        if ('' === $filename) {
            self::warn($ctx, 'simplexml_load_file(): failed to load external entity ""', $frame);

            return null;
        }
        $contents = VmFsReadNative::read($filename);
        if (false === $contents) {
            // php-src php_sxe_load_file / libxml error handler — I/O warning + entity path (#25295).
            self::warn(
                $ctx,
                'simplexml_load_file(): I/O warning : failed to load external entity "'.$filename.'"',
                $frame
            );

            return null;
        }

        return self::loadString($ctx, $contents, $frame, $class);
    }

    public static function requireElement(ObjectEntry $entry, string $label): ObjectEntry
    {
        // Accept SimpleXMLElement subclasses (php-src; needed for simplexml_import_dom class_name, #20291).
        if (self::CLASS_LC !== strtolower($entry->class->name)
            && self::CLASS_LC !== ($entry->class->parentLc ?? '')) {
            throw new \TypeError(sprintf('%s(): Argument must be SimpleXMLElement, %s given', $label, $entry->class->name));
        }
        if (!SimpleXmlRegistry::has($entry)) {
            throw new \LogicException($label.'(): SimpleXMLElement has no node state');
        }

        return $entry;
    }

    /**
     * $sxe->name property read (php-src sxe_prop_dim_read; #21667).
     *
     * On attributes() views, named properties resolve attributes (missing ⇒ null).
     * On children()/element nodes, named properties are live child selections.
     */
    public static function childByName(Context $ctx, ObjectEntry $entry, string $name): ?ObjectEntry
    {
        $docKey = SimpleXmlRegistry::documentKey($entry);
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            $map = self::attributesMap($entry);
            if (!\array_key_exists($name, $map)) {
                // php-src: missing attribute property read yields null (not an empty SXE).
                return null;
            }

            // Same shape as attributes() foreach / $sxe['attr'] (#19351, #22733, #22654).
            return self::wrapAttributeNode($ctx, $entry, $name);
        }
        // Property access is always a live named-sibling selection under the context
        // parent (php-src sxe.c; #20483) — never a frozen snapshot or bare single node.
        // From a children() view, inherit the NS filter so `$ch->localName` matches by
        // local name within that filter (#22728 / #22829).
        $childrenFilter = SimpleXmlRegistry::isChildrenView($entry)
            ? SimpleXmlRegistry::childrenViewFilter($entry)
            : null;

        return self::wrapNamedChildView(
            $ctx,
            $entry->class,
            self::propertyAccessParent($entry),
            $name,
            $docKey,
            $childrenFilter
        );
    }

    /**
     * isset($sxe->child) — true when a matching child element exists (#19707, sxe.c has_property).
     * On attributes() views, true when the named attribute is present (#21667).
     */
    public static function childPropertyExists(ObjectEntry $entry, string $name): bool
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return \array_key_exists($name, self::attributesMap($entry));
        }

        return [] !== self::matchingElements($entry, $name);
    }

    /**
     * Whether $entry is a SimpleXMLElement (or subclass) with node state for bool cast (#22714).
     */
    public static function handlesObjectCast(ObjectEntry $entry): bool
    {
        if (!SimpleXmlRegistry::has($entry)) {
            return false;
        }
        $lc = strtolower($entry->class->name);
        if (self::CLASS_LC === $lc || VmSimpleXmlIterator::CLASS_LC === $lc) {
            return true;
        }

        return self::CLASS_LC === ($entry->class->parentLc ?? '');
    }

    /**
     * (int)$sxe — php-src sxe_object_cast_ex(IS_LONG): stringify node text, then convert (#22715).
     *
     * Empty text ⇒ 0; no E_WARNING (unlike plain objects → 1).
     *
     * @return int|null null when $entry is not a SimpleXML cast handler
     */
    public static function tryCastObjectToInt(ObjectEntry $entry): ?int
    {
        if (!self::handlesObjectCast($entry)) {
            return null;
        }

        return (int) self::textContent($entry);
    }

    /**
     * (float)$sxe — php-src sxe_object_cast_ex(IS_DOUBLE): stringify node text, then convert (#22715).
     *
     * @return float|null null when $entry is not a SimpleXML cast handler
     */
    public static function tryCastObjectToFloat(ObjectEntry $entry): ?float
    {
        if (!self::handlesObjectCast($entry)) {
            return null;
        }

        return (float) self::textContent($entry);
    }

    /**
     * (bool)$sxe / empty($sxeVar) — php-src sxe_object_cast_ex(_IS_BOOL) (#22714).
     *
     * Present element/attribute node ⇒ true (even when text is empty or "0").
     * Missing named-child selection / empty root without attrs ⇒ false.
     * Distinct from empty($sxe->child) property path (#19707), which uses string emptiness.
     */
    public static function objectIsTruthy(ObjectEntry $entry): bool
    {
        // php-src sxe_object_cast_ex(_IS_BOOL) → php_sxe_get_first_node (resets on <8.4; #27717).
        self::maybeImplicitlyResetIterator($entry);
        if (SimpleXmlRegistry::isAttributeNodeView($entry)) {
            $name = SimpleXmlRegistry::attributeNodeName($entry);

            return \array_key_exists($name, SimpleXmlRegistry::state($entry)->attributes);
        }
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return [] !== self::attributesMap($entry);
        }
        // Named child / children() / frozen multi-match: get_first_node non-null ⇒ true.
        if (SimpleXmlRegistry::isNamedChildView($entry)
            || SimpleXmlRegistry::isChildrenView($entry)
            || SimpleXmlRegistry::isView($entry)) {
            return [] !== self::viewElements($entry);
        }

        // Element node: !sxe_prop_is_empty (attrs, element children, or non-empty text).
        return !self::elementPropIsEmpty(SimpleXmlRegistry::state($entry));
    }

    /** php-src sxe_prop_is_empty for an element node (ITER_NONE / root). */
    private static function elementPropIsEmpty(SimpleXmlNodeState $state): bool
    {
        if ([] !== $state->attributes) {
            return false;
        }
        if ([] !== $state->children) {
            return false;
        }

        return '' === $state->text;
    }

    /**
     * empty($sxe->child) — missing child, or present child whose string cast is empty (#19707).
     *
     * php-src sxe has_property with ZEND_ISEMPTY checks the concatenated text of matching
     * children (not object truthiness — SimpleXMLElement objects are always truthy).
     * On attributes() views, uses the attribute value the same way (#21667).
     */
    public static function childPropertyIsEmpty(ObjectEntry $entry, string $name): bool
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            $map = self::attributesMap($entry);
            if (!\array_key_exists($name, $map)) {
                return true;
            }
            $text = $map[$name];

            return '' === $text || '0' === $text;
        }
        $elements = self::matchingElements($entry, $name);
        if ([] === $elements) {
            return true;
        }
        $text = '';
        foreach ($elements as $node) {
            $text .= $node->text;
        }

        return '' === $text || '0' === $text;
    }

    /**
     * empty($sxe[$dim]) — php-src sxe_prop_dim_exists(check_empty) (#25338).
     *
     * Distinct from empty($heldSxe): attribute/element objects stay truthy under (bool)/empty($var)
     * when the node exists; dimension empty uses string emptiness (and element children).
     */
    public static function dimensionIsEmpty(ObjectEntry $entry, Variable $offset): bool
    {
        $offset = $offset->resolveIndirect();
        if (!self::offsetExists($entry, $offset)) {
            return true;
        }
        if (Variable::TYPE_STRING === $offset->type) {
            $name = $offset->toString();
            if (SimpleXmlRegistry::isAttributesView($entry)) {
                $text = self::attributesMap($entry)[$name] ?? '';
            } elseif (SimpleXmlRegistry::isNamedChildView($entry)) {
                $matches = self::namedChildViewElements($entry);
                $text = ([] === $matches) ? '' : ($matches[0]->attributes[$name] ?? '');
            } else {
                $text = SimpleXmlRegistry::state($entry)->attributes[$name] ?? '';
            }

            return self::textIsEmptyForDimEmpty($text);
        }
        if (Variable::TYPE_INTEGER === $offset->type) {
            $index = $offset->toInt();
            if (SimpleXmlRegistry::isAttributesView($entry)) {
                $values = array_values(self::attributesMap($entry));

                return self::textIsEmptyForDimEmpty($values[$index] ?? '');
            }
            $elements = self::viewElements($entry);
            $node = $elements[$index] ?? null;
            if (null === $node) {
                return true;
            }
            // Element dims: child elements keep the node non-empty even when direct text is ''.
            if ([] !== $node->children) {
                return false;
            }

            return self::textIsEmptyForDimEmpty($node->text);
        }

        return true;
    }

    /** php-src empty() string rule used by SXE has_dimension(check_empty). */
    private static function textIsEmptyForDimEmpty(string $text): bool
    {
        return '' === $text || '0' === $text;
    }

    /**
     * unset($sxe->child) — unlink all direct child elements with the given name (#19681, sxe_prop_dim_delete).
     */
    public static function unsetChildProperty(ObjectEntry $entry, string $name): void
    {
        if ('' === $name || SimpleXmlRegistry::isAttributesView($entry)) {
            return;
        }
        if (SimpleXmlRegistry::isView($entry)) {
            $docKey = SimpleXmlRegistry::documentKey($entry);
            foreach (self::viewElements($entry) as $node) {
                self::removeNodeFromTree($docKey, $node);
            }

            return;
        }
        SimpleXmlRegistry::state($entry)->removeElementsNamed($name);
    }

    /**
     * $sxe->child = $value — element text / new child (php-src sxe_prop_dim_write; #20539).
     *
     * On an attributes() view, updates an existing attribute by name (new names are ignored —
     * matches observed Zend ATTRLIST property-write behaviour when the parent node is an attr).
     */
    public static function setChildProperty(
        Context $ctx,
        ObjectEntry $entry,
        string $name,
        Variable $value,
        ?Frame $frame = null
    ): void {
        if ('' === $name) {
            throw new \ValueError('Cannot create element with an empty name');
        }

        $value = $value->resolveIndirect();
        $stringValue = self::coercePropertyWriteValue($value, false, $frame);

        if (SimpleXmlRegistry::isAttributesView($entry)) {
            $state = SimpleXmlRegistry::state($entry);
            if (!\array_key_exists($name, self::attributesMap($entry))) {
                return;
            }
            $state->attributes[$name] = $stringValue;
            VmDomSimpleXmlBridge::syncDomAttributeFromSimpleXml($ctx, $state, $name, $stringValue);

            return;
        }

        $matches = self::matchingElements($entry, $name);
        $count = \count($matches);
        if (1 === $count) {
            $node = $matches[0];
            $node->replaceText($stringValue);
            VmDomSimpleXmlBridge::syncDomTextFromSimpleXml($ctx, $node, $stringValue);

            return;
        }
        if ($count > 1) {
            // php-src php_error_docref(NULL, E_WARNING, …) — no method prefix (#20539).
            self::warn(
                $ctx,
                'Cannot assign to an array of nodes (duplicate subnodes or attr detected)',
                $frame
            );

            return;
        }

        // No match — create a new child under the property-access parent (php-src xmlNewTextChild).
        $parent = self::propertyAccessParent($entry);
        if ('' === $parent->name && !SimpleXmlRegistry::isView($entry) && !SimpleXmlRegistry::isAttributesView($entry)) {
            // Detached / empty placeholder — nowhere to attach.
            return;
        }
        $child = new SimpleXmlNodeState($name);
        $child->replaceText($stringValue);
        $parent->appendElement($child);
    }

    /**
     * Coerce RHS for sxe_prop_dim_write — scalars + SimpleXMLElement cast; reject complex types.
     *
     * @throws \TypeError
     */
    private static function coercePropertyWriteValue(
        Variable $value,
        bool $attribs,
        ?Frame $frame
    ): string {
        $value = $value->resolveIndirect();
        $vm = $frame?->vmContext?->vm ?? null;

        if ($value->isVmResource()) {
            throw new \TypeError(self::complexPropertyWriteTypeError($attribs, 'resource'));
        }

        switch ($value->type) {
            case Variable::TYPE_NULL:
            case Variable::TYPE_UNDEFINED:
            case Variable::TYPE_INTEGER:
            case Variable::TYPE_FLOAT:
            case Variable::TYPE_BOOLEAN:
            case Variable::TYPE_STRING:
                return $value->toString($vm, $frame);
            case Variable::TYPE_OBJECT:
                $object = $value->toObject();
                if (ResourceSupport::isResourceObject($object)) {
                    throw new \TypeError(self::complexPropertyWriteTypeError($attribs, 'resource'));
                }
                if (self::CLASS_LC === strtolower($object->class->name)) {
                    return $value->toString($vm, $frame);
                }

                throw new \TypeError(self::complexPropertyWriteTypeError($attribs, $object->class->name));
            case Variable::TYPE_ARRAY:
                throw new \TypeError(self::complexPropertyWriteTypeError($attribs, 'array'));
            default:
                throw new \TypeError(self::complexPropertyWriteTypeError($attribs, 'mixed'));
        }
    }

    private static function complexPropertyWriteTypeError(bool $attribs, string $given): string
    {
        return sprintf(
            "It's not possible to assign a complex type to %s, %s given",
            $attribs ? 'attributes' : 'properties',
            $given
        );
    }

    /**
     * Remove a node from the in-memory tree by reference (shared SimpleXmlNodeState objects).
     */
    private static function removeNodeFromTree(int $documentKey, SimpleXmlNodeState $target): void
    {
        $root = SimpleXmlRegistry::rootState($documentKey);
        self::removeNodeFromParent($root, $target);
    }

    private static function removeNodeFromParent(SimpleXmlNodeState $parent, SimpleXmlNodeState $target): bool
    {
        foreach ($parent->children as $child) {
            if ($child === $target) {
                $parent->removeElement($target);
                // Live xpath handles keep the ObjectEntry but stringify empty (#20483).
                $target->markDetached();

                return true;
            }
            if (self::removeNodeFromParent($child, $target)) {
                return true;
            }
        }

        return false;
    }

    public static function offsetGet(Context $ctx, ObjectEntry $entry, Variable $offset): Variable
    {
        $offset = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER === $offset->type) {
            $index = $offset->toInt();
            if (SimpleXmlRegistry::isAttributesView($entry)) {
                // php-src sxe_prop_dim_read: attribute dimension returns SXE attr node (#22733).
                $map = self::attributesMap($entry);
                $names = array_keys($map);
                if ($index < 0 || $index >= \count($names)) {
                    $result = new Variable();
                    $result->null();

                    return $result;
                }
                $name = $names[$index];
                $result = new Variable();
                $result->object(self::wrapAttributeNode($ctx, $entry, $name));

                return $result;
            }
            $elements = self::viewElements($entry);
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
            if (SimpleXmlRegistry::isAttributesView($entry)) {
                $value = self::attributesMap($entry)[$name] ?? null;
            } elseif (SimpleXmlRegistry::isNamedChildView($entry)) {
                $matches = self::namedChildViewElements($entry);
                $value = ([] === $matches) ? null : ($matches[0]->attributes[$name] ?? null);
            } else {
                $value = SimpleXmlRegistry::state($entry)->attributes[$name] ?? null;
            }
            $result = new Variable();
            if (null === $value) {
                $result->null();
            } else {
                // php-src sxe_prop_dim_read: $sxe['attr'] is live SimpleXMLElement (#22733, #22654).
                $result->object(self::wrapAttributeNode($ctx, $entry, $name));
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
                $values = array_values(self::attributesMap($entry));

                return $index >= 0 && $index < \count($values);
            }
            $elements = self::viewElements($entry);

            return $index >= 0 && $index < \count($elements);
        }
        if (Variable::TYPE_STRING === $offset->type) {
            if (SimpleXmlRegistry::isAttributesView($entry)) {
                return \array_key_exists($offset->toString(), self::attributesMap($entry));
            }
            if (SimpleXmlRegistry::isNamedChildView($entry)) {
                $matches = self::namedChildViewElements($entry);

                return [] !== $matches && \array_key_exists($offset->toString(), $matches[0]->attributes);
            }
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
                $keys = array_keys(self::attributesMap($entry));
                if ($index < 0 || $index >= \count($keys)) {
                    self::warn($ctx, 'SimpleXMLElement::offsetSet(): Cannot add attribute number '.$index.' when only '.\count($keys).' such attributes exist', $frame);

                    return;
                }
                $state->attributes[$keys[$index]] = $stringValue;

                return;
            }
            $elements = self::viewElements($entry);
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
            $node->replaceText($stringValue);
            // Live DOM peer — same libxml node in php-src (#20137).
            VmDomSimpleXmlBridge::syncDomTextFromSimpleXml($ctx, $node, $stringValue);

            return;
        }

        $name = $offset->toString($vm, $frame);
        if ('' === $name) {
            throw new \ValueError('Cannot create attribute with an empty name');
        }

        if (SimpleXmlRegistry::isAttributesView($entry)) {
            $state = SimpleXmlRegistry::state($entry);
            if (!\array_key_exists($name, self::attributesMap($entry))) {
                // php-src: attributes() view cannot introduce new attributes.
                return;
            }
            $attrValue = $value->toString($vm, $frame);
            $state->attributes[$name] = $attrValue;
            VmDomSimpleXmlBridge::syncDomAttributeFromSimpleXml($ctx, $state, $name, $attrValue);

            return;
        }

        if (SimpleXmlRegistry::isView($entry)) {
            $elements = self::viewElements($entry);
            if ([] === $elements) {
                return;
            }
            // php-src: dimension write on a multi-node selection updates the first node.
            $attrValue = $value->toString($vm, $frame);
            $elements[0]->attributes[$name] = $attrValue;
            VmDomSimpleXmlBridge::syncDomAttributeFromSimpleXml($ctx, $elements[0], $name, $attrValue);

            return;
        }

        $attrValue = $value->toString($vm, $frame);
        $state = SimpleXmlRegistry::state($entry);
        $state->attributes[$name] = $attrValue;
        VmDomSimpleXmlBridge::syncDomAttributeFromSimpleXml($ctx, $state, $name, $attrValue);
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
                $keys = array_keys(self::attributesMap($entry));
                if ($index >= 0 && $index < \count($keys)) {
                    unset($state->attributes[$keys[$index]]);
                }

                return;
            }
            $elements = self::viewElements($entry);
            if ($index >= 0 && $index < \count($elements)) {
                self::removeNodeFromTree(SimpleXmlRegistry::documentKey($entry), $elements[$index]);
            }

            return;
        }

        $name = $offset->toString($vm, $frame);
        if ('' === $name) {
            return;
        }
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            if (!\array_key_exists($name, self::attributesMap($entry))) {
                return;
            }
            unset(SimpleXmlRegistry::state($entry)->attributes[$name]);

            return;
        }
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            $matches = self::namedChildViewElements($entry);
            if ([] !== $matches) {
                unset($matches[0]->attributes[$name]);
            }

            return;
        }
        $state = SimpleXmlRegistry::state($entry);
        unset($state->attributes[$name]);
    }

    public static function countElements(ObjectEntry $entry): int
    {
        if (SimpleXmlRegistry::isAttributeNodeView($entry)) {
            // php-src: attribute SXE handles are not iterable collections (#22654).
            return 0;
        }
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return \count(self::attributesMap($entry));
        }
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            return \count(self::namedChildViewElements($entry));
        }
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            return \count(self::childrenViewElements($entry));
        }
        if (SimpleXmlRegistry::isView($entry)) {
            return \count(self::viewElements($entry));
        }

        return \count(SimpleXmlRegistry::state($entry)->children);
    }

    public static function elementName(ObjectEntry $entry): string
    {
        self::assertNodeInitialized($entry, 'SimpleXMLElement::getName()');
        // php-src zim_SimpleXMLElement_getName → php_sxe_get_first_node (resets on <8.4; #27717).
        self::maybeImplicitlyResetIterator($entry);
        if (SimpleXmlRegistry::isAttributeNodeView($entry)) {
            $name = SimpleXmlRegistry::attributeNodeName($entry);
            $owner = SimpleXmlRegistry::state($entry);
            if (!\array_key_exists($name, $owner->attributes)) {
                throw new \Error('SimpleXMLElement is not properly initialized');
            }

            return self::localNameFromQualified($name);
        }
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            $attrs = self::attributesMap($entry);
            if ([] === $attrs) {
                return '';
            }
            /** @var string $first */
            $first = array_key_first($attrs);

            return self::localNameFromQualified($first);
        }
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            return self::localNameFromQualified(SimpleXmlRegistry::namedChildViewName($entry));
        }
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            $view = self::childrenViewElements($entry);
            if ([] === $view) {
                return '';
            }

            return self::localNameFromQualified($view[0]->name);
        }
        if (SimpleXmlRegistry::isView($entry)) {
            $view = self::viewElements($entry);
            if ([] === $view) {
                return '';
            }

            return self::localNameFromQualified($view[0]->name);
        }

        return self::localNameFromQualified(SimpleXmlRegistry::state($entry)->name);
    }

    /** Local name after optional `prefix:` (php-src sxe iterator key / getName; #20136). */
    public static function localNameFromQualified(string $qualifiedName): string
    {
        $colon = strpos($qualifiedName, ':');

        return false === $colon ? $qualifiedName : substr($qualifiedName, $colon + 1);
    }

    public static function children(Context $ctx, ObjectEntry $entry, ?string $namespaceOrPrefix = null, bool $isPrefix = true): ObjectEntry
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return self::wrapView($ctx, $entry->class, [], SimpleXmlRegistry::documentKey($entry));
        }

        // Named property views (`$sxe->foo`): children() is relative to the first match
        // (php-src sxe.c; #20483). Frozen multi-match snapshots keep prior behavior.
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            $matches = self::namedChildViewElements($entry);
            if ([] === $matches) {
                return self::wrapView($ctx, $entry->class, [], SimpleXmlRegistry::documentKey($entry));
            }

            return self::wrapChildrenView(
                $ctx,
                $entry->class,
                $matches[0],
                SimpleXmlRegistry::documentKey($entry),
                $namespaceOrPrefix,
                $isPrefix
            );
        }
        if (SimpleXmlRegistry::isView($entry) && !SimpleXmlRegistry::isChildrenView($entry)) {
            $elements = self::directElementChildren($entry);
            $scope = self::inScopeNamespacesForEntry($entry);
            // php-src sxe_children: null/'' ⇒ unprefixed element children only (still include
            // default-xmlns nodes); non-empty ⇒ URI/prefix filter (#22737, re-#19342).
            if (null === $namespaceOrPrefix || '' === $namespaceOrPrefix) {
                $elements = self::filterUnprefixedElementChildren($elements);
            } else {
                $elements = self::filterChildrenByNamespace($elements, $namespaceOrPrefix, $isPrefix, $entry, $scope);
            }

            return self::wrapView($ctx, $entry->class, $elements, SimpleXmlRegistry::documentKey($entry));
        }

        // Live children view: share the parent node state; filter is applied on each read (#20331).
        return self::wrapChildrenView(
            $ctx,
            $entry->class,
            SimpleXmlRegistry::state($entry),
            SimpleXmlRegistry::documentKey($entry),
            $namespaceOrPrefix,
            $isPrefix
        );
    }

    /**
     * @return ObjectEntry|null null when the receiver has no element node (empty children()/
     *                      named-child view) — php-src sxe.c; #25148
     */
    public static function attributes(Context $ctx, ObjectEntry $entry, ?string $namespaceOrPrefix = null, bool $isPrefix = true): ?ObjectEntry
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return self::wrapAttributesView($ctx, $entry->class, new SimpleXmlNodeState(''), SimpleXmlRegistry::documentKey($entry));
        }
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            $matches = self::namedChildViewElements($entry);
            if ([] === $matches) {
                // php-src: attributes() on an empty node list returns null (#25148).
                return null;
            }

            return self::wrapAttributesView(
                $ctx,
                $entry->class,
                $matches[0],
                SimpleXmlRegistry::documentKey($entry),
                $namespaceOrPrefix,
                $isPrefix
            );
        }
        // children() views: php-src applies attributes() to the first matching child
        // (same first-element context as getName()/__toString); empty ⇒ null (#25148).
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            $matches = self::childrenViewElements($entry);
            if ([] === $matches) {
                return null;
            }

            return self::wrapAttributesView(
                $ctx,
                $entry->class,
                $matches[0],
                SimpleXmlRegistry::documentKey($entry),
                $namespaceOrPrefix,
                $isPrefix
            );
        }
        if (SimpleXmlRegistry::isView($entry)) {
            return self::wrapAttributesView($ctx, $entry->class, new SimpleXmlNodeState(''), SimpleXmlRegistry::documentKey($entry));
        }

        // Live attributes view: share the element node state; filter is applied on each read (#20332).
        return self::wrapAttributesView(
            $ctx,
            $entry->class,
            SimpleXmlRegistry::state($entry),
            SimpleXmlRegistry::documentKey($entry),
            $namespaceOrPrefix,
            $isPrefix
        );
    }

    public static function asXml(ObjectEntry $entry, bool $includeDeclaration = false): string|false
    {
        self::assertNodeInitialized($entry, 'SimpleXMLElement::asXML()');
        // php-src zim_SimpleXMLElement_asXML → php_sxe_get_first_node (resets on <8.4; #27717).
        // Must run before ATTRLIST early-false — Zend still rewinds then (#27717 attrs LOOP).
        self::maybeImplicitlyResetIterator($entry);
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return false;
        }
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            $parts = [];
            foreach (self::namedChildViewElements($entry) as $node) {
                $parts[] = self::serializeNode($node);
            }
            $body = implode('', $parts);
            if ('' === $body) {
                return false;
            }

            // php-src sxe_as_xml: document serialization ends with trailing newline (#19934, re-#19681).
            return $includeDeclaration ? '<?xml version="1.0"?>'."\n".$body."\n" : $body;
        }
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            $parts = [];
            foreach (self::childrenViewElements($entry) as $node) {
                $parts[] = self::serializeNode($node);
            }
            $body = implode('', $parts);
            if ('' === $body) {
                return false;
            }

            // php-src sxe_as_xml: document serialization ends with trailing newline (#19934, re-#19681).
            return $includeDeclaration ? '<?xml version="1.0"?>'."\n".$body."\n" : $body;
        }
        if (SimpleXmlRegistry::isView($entry)) {
            $parts = [];
            foreach (self::viewElements($entry) as $node) {
                $parts[] = self::serializeNode($node);
            }
            $body = implode('', $parts);
            if ('' === $body) {
                return false;
            }

            // php-src sxe_as_xml: document serialization ends with trailing newline (#19934, re-#19681).
            return $includeDeclaration ? '<?xml version="1.0"?>'."\n".$body."\n" : $body;
        }

        $state = SimpleXmlRegistry::state($entry);
        $body = self::serializeNode($state);

        // php-src sxe_as_xml: document serialization ends with trailing newline (#19934, re-#19681).
        return $includeDeclaration ? '<?xml version="1.0"?>'."\n".$body."\n" : $body;
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
        if (SimpleXmlRegistry::isNamedChildView($entry) || SimpleXmlRegistry::isView($entry) || SimpleXmlRegistry::isAttributesView($entry)) {
            throw new \LogicException('SimpleXMLElement::addChild() cannot be called on a view in this compiler build');
        }

        // php-src zim_SimpleXMLElement_addChild: xmlNewChild uses localname only; ns is
        // attached via xmlSearchNsByHref on the parent, else xmlNewNs on the new child
        // (php-src ext/simplexml/simplexml.c; #19906, #22734).
        $localName = self::localNameFromQualified($qualifiedName);
        $colon = strpos($qualifiedName, ':');
        $prefixFromName = (false !== $colon) ? substr($qualifiedName, 0, $colon) : null;
        if (null !== $prefixFromName && '' === $prefixFromName) {
            $prefixFromName = null;
        }

        $childName = $localName;
        $childAttrs = [];
        if (null !== $namespace && '' !== $namespace) {
            $docKey = SimpleXmlRegistry::documentKey($entry);
            $root = SimpleXmlRegistry::rootState($docKey);
            $parent = SimpleXmlRegistry::state($entry);
            $existingPrefix = self::searchNsPrefixByHref($root, $parent, $namespace);
            if (null !== $existingPrefix) {
                // Reuse in-scope ns node — no redundant xmlns on the child (#22734).
                $childName = '' === $existingPrefix ? $localName : $existingPrefix.':'.$localName;
            } else {
                if (null !== $prefixFromName) {
                    $childAttrs['xmlns:'.$prefixFromName] = $namespace;
                    $childName = $prefixFromName.':'.$localName;
                } else {
                    $childAttrs['xmlns'] = $namespace;
                    $childName = $localName;
                }
            }
        }

        $child = new SimpleXmlNodeState($childName, $childAttrs);
        if (null !== $value && '' !== $value) {
            $child->replaceText($value);
        }
        SimpleXmlRegistry::state($entry)->appendElement($child);

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

        // php-src zim_simplexmlelement_addAttribute: namespaced attrs require a non-empty prefix
        // (#19708 — Attribute requires prefix for namespace; leave element unchanged).
        if (null !== $namespace && '' !== $namespace) {
            $colon = strpos($qualifiedName, ':');
            $prefix = false !== $colon ? substr($qualifiedName, 0, $colon) : '';
            if ('' === $prefix) {
                self::warn(
                    $ctx,
                    'SimpleXMLElement::addAttribute(): Attribute requires prefix for namespace',
                    $frame
                );

                return;
            }
            $xmlnsKey = 'xmlns:'.$prefix;
            if (!\array_key_exists($xmlnsKey, $state->attributes)) {
                $state->attributes[$xmlnsKey] = $namespace;
            }
        }

        $state->attributes[$qualifiedName] = $value;
    }

    /**
     * SimpleXMLElement::xpath — php-src zim_simplexmlelement_xpath (#20334, #22720).
     *
     * Prefixes resolve from in-scope xmlns (xmlGetNsList) plus registerXPathNamespace
     * (xmlXPathRegisterNs). `//name` is document-wide (absolute), not context-scoped.
     * Invalid expressions follow php-src: E_WARNING "Invalid expression" + false
     * (xmlXPathEvalExpression failure) — not an empty node-set.
     *
     * @return HashTable|false list of SimpleXMLElement objects, or false on failure
     */
    public static function xpath(Context $ctx, ObjectEntry $entry, string $expression, ?Frame $frame = null): HashTable|false
    {
        $expression = trim($expression);
        $ht = new HashTable();
        // php-src: empty / whitespace-only → xmlXPathEval failure (#22720).
        if ('' === $expression || self::isInvalidXPathExpression($expression)) {
            self::warn($ctx, 'SimpleXMLElement::xpath(): Invalid expression', $frame);

            return false;
        }

        $nsMap = self::xpathNamespaceMap($entry);
        $contextNodes = self::xpathContextNodes($entry);
        $docKey = SimpleXmlRegistry::documentKey($entry);

        // `//tag` / `//ns:tag` / `//tag[@attr="v"]` / `//tag[@attr=N]` — absolute from document root (XPath //).
        // Unquoted numeric RHS uses XPath 1.0 number equality (php-src/libxml; peer DOM #24333 / #24340).
        if (preg_match(
            '~^//([\w:-]+)(?:\[@([\w:-]+)=(?:["\']([^"\']*)["\']|([+-]?(?:\d+\.?\d*|\.\d+)))\])?$~',
            $expression,
            $m
        )) {
            if (!self::isValidXPathNameTest($m[1]) || (isset($m[2]) && '' !== $m[2] && !self::isValidXPathNameTest($m[2]))) {
                self::warn($ctx, 'SimpleXMLElement::xpath(): Invalid expression', $frame);

                return false;
            }
            $resolved = self::resolveXPathQName($m[1], $nsMap);
            if (null === $resolved) {
                self::warn($ctx, 'SimpleXMLElement::xpath(): Undefined namespace prefix', $frame);

                return false;
            }
            [$localName, $nsUri] = $resolved;
            $numericCompare = isset($m[4]) && '' !== $m[4];
            $attrName = isset($m[2]) && '' !== $m[2] ? $m[2] : null;
            $attrExpected = $numericCompare ? $m[4] : ($m[3] ?? '');
            $root = SimpleXmlRegistry::rootState($docKey);
            foreach (self::collectDescendantsByQName($root, $localName, $nsUri, []) as $node) {
                if (null !== $attrName) {
                    if (!\array_key_exists($attrName, $node->attributes)
                        || !self::xpathAttributeEquals($node->attributes[$attrName], $attrExpected, $numericCompare)
                    ) {
                        continue;
                    }
                }
                $var = new Variable();
                $var->object(self::wrapNode($ctx, $entry->class, $node, $docKey));
                $ht->append($var);
            }

            return $ht;
        }
        if ('.' === $expression) {
            foreach ($contextNodes as $context) {
                $var = new Variable();
                $var->object(self::wrapNode($ctx, $entry->class, $context, $docKey));
                $ht->append($var);
            }

            return $ht;
        }

        if (str_starts_with($expression, '/')) {
            // `/` alone → empty node-set; `//` / `///` / trailing `/` → invalid (libxml).
            if ('/' === $expression) {
                return $ht;
            }
            if (preg_match('#^/{2,}$#', $expression) || str_ends_with($expression, '/')) {
                self::warn($ctx, 'SimpleXMLElement::xpath(): Invalid expression', $frame);

                return false;
            }
            $segments = array_values(array_filter(explode('/', $expression), static fn (string $part): bool => '' !== $part));
            if ([] === $segments) {
                return $ht;
            }
            // Each entry: [node, in-scope xmlns map at that node].
            /** @var list<array{0: SimpleXmlNodeState, 1: array<string, string>}> $frontier */
            $frontier = [[SimpleXmlRegistry::rootState($docKey), []]];
            foreach ($segments as $index => $segment) {
                if (!self::isValidXPathNameTest($segment)) {
                    self::warn($ctx, 'SimpleXMLElement::xpath(): Invalid expression', $frame);

                    return false;
                }
                $resolved = self::resolveXPathQName($segment, $nsMap);
                if (null === $resolved) {
                    self::warn($ctx, 'SimpleXMLElement::xpath(): Undefined namespace prefix', $frame);

                    return false;
                }
                [$localName, $nsUri] = $resolved;
                $next = [];
                if (0 === $index) {
                    foreach ($frontier as [$node, $parentScope]) {
                        $nodeScope = self::scopeAfterNode($node, $parentScope);
                        if (self::nodeMatchesQName($node, $localName, $nsUri, $nodeScope)) {
                            $next[] = [$node, $nodeScope];
                        }
                    }
                } else {
                    foreach ($frontier as [$node, $nodeScope]) {
                        foreach ($node->children as $child) {
                            if (self::nodeMatchesQName($child, $localName, $nsUri, $nodeScope)) {
                                $next[] = [$child, self::scopeAfterNode($child, $nodeScope)];
                            }
                        }
                    }
                }
                $frontier = $next;
                if ([] === $frontier) {
                    break;
                }
            }
            foreach ($frontier as [$node]) {
                $var = new Variable();
                $var->object(self::wrapNode($ctx, $entry->class, $node, $docKey));
                $ht->append($var);
            }

            return $ht;
        }

        if (preg_match('~^[\w:-]+$~', $expression)) {
            if (!self::isValidXPathNameTest($expression)) {
                self::warn($ctx, 'SimpleXMLElement::xpath(): Invalid expression', $frame);

                return false;
            }
            $resolved = self::resolveXPathQName($expression, $nsMap);
            if (null === $resolved) {
                self::warn($ctx, 'SimpleXMLElement::xpath(): Undefined namespace prefix', $frame);

                return false;
            }
            [$localName, $nsUri] = $resolved;
            $root = SimpleXmlRegistry::rootState($docKey);
            foreach ($contextNodes as $context) {
                $parentScope = self::namespacesAtNodeWalk($root, $context, []) ?? [];
                foreach ($context->children as $node) {
                    if (self::nodeMatchesQName($node, $localName, $nsUri, $parentScope)) {
                        $var = new Variable();
                        $var->object(self::wrapNode($ctx, $entry->class, $node, $docKey));
                        $ht->append($var);
                    }
                }
            }

            return $ht;
        }

        // Subset gap: valid XPath we do not evaluate yet → empty node-set (not false).
        // Clearly-invalid forms already rejected above via isInvalidXPathExpression.
        return $ht;
    }

    /**
     * Attribute predicate match for SimpleXML xpath. Quoted RHS is string equality;
     * unquoted numeric RHS uses XPath 1.0 number conversion (php-src sxe.c / libxml; #24340).
     */
    private static function xpathAttributeEquals(string $actual, string $expected, bool $numericCompare): bool
    {
        if (!$numericCompare) {
            return $actual === $expected;
        }
        $left = trim($actual);
        $right = trim($expected);
        if ('' === $left || !is_numeric($left) || '' === $right || !is_numeric($right)) {
            return false;
        }

        return (float) $left === (float) $right;
    }

    /**
     * Conservative invalid-expression detector for the SimpleXML xpath subset (#22720).
     * Matches common libxml xmlXPathEvalExpression failures without flagging valid
     * location paths / node-tests we still return as empty node-sets.
     */
    private static function isInvalidXPathExpression(string $expression): bool
    {
        if ('' === $expression) {
            return true;
        }
        if (preg_match('#^/{2,}$#', $expression)) {
            return true;
        }
        if (str_ends_with($expression, '/') && '/' !== $expression) {
            return true;
        }
        if ('[' === $expression || ']' === $expression || '()' === $expression || './' === $expression) {
            return true;
        }
        if (substr_count($expression, '[') !== substr_count($expression, ']')) {
            return true;
        }
        if (substr_count($expression, '(') !== substr_count($expression, ')')) {
            return true;
        }
        // Bare `@` / `@@@` — attribute axis requires a NameTest.
        if (preg_match('/^@+$/', $expression)) {
            return true;
        }
        // `!` is only legal inside `!=` (XPath 1.0).
        if (preg_match('/!(?!=)/', $expression)) {
            return true;
        }

        return false;
    }

    /** XPath NameTest / QName for the location-path subset (`*`, NCName, NCName:NCName). */
    private static function isValidXPathNameTest(string $name): bool
    {
        if ('*' === $name) {
            return true;
        }

        return 1 === preg_match('/^[A-Za-z_][\w.-]*(?::[A-Za-z_][\w.-]*)?$/', $name);
    }

    /**
     * Namespaces in use on the element / attributes (php-src sxe_add_namespaces; #22729).
     * Unlike getDocNamespaces(), unused xmlns declarations are omitted; inherited default
     * / prefixed NS on the node itself are included.
     *
     * @return HashTable string map
     */
    public static function getNamespaces(ObjectEntry $entry, bool $recursive = false): HashTable
    {
        return self::stringMapToHashTable(self::collectUsedNamespaces($entry, $recursive));
    }

    /** @return HashTable string map */
    public static function getDocNamespaces(ObjectEntry $entry, bool $recursive = false, bool $fromRoot = true): HashTable
    {
        return self::stringMapToHashTable(self::collectRegisteredNamespaces($entry, $recursive, $fromRoot));
    }

    public static function registerXPathNamespace(ObjectEntry $entry, string $prefix, string $namespaceUri): bool
    {
        return SimpleXmlRegistry::registerXPathNamespace($entry, $prefix, $namespaceUri);
    }

    /** @return list<SimpleXmlNodeState> */
    public static function directElementChildren(ObjectEntry $entry): array
    {
        if (SimpleXmlRegistry::isAttributeNodeView($entry)) {
            return [];
        }
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            $out = [];
            foreach (self::attributesMap($entry) as $name => $value) {
                // php-src sxe.c: attributes() foreach yields name => attribute SimpleXMLElement (#19351).
                // Placeholder nodes carry the attr name; wrapChild promotes them to live handles (#22654).
                $out[] = new SimpleXmlNodeState($name, [], [], $value);
            }

            return $out;
        }
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            return self::namedChildViewElements($entry);
        }
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            return self::childrenViewElements($entry);
        }
        if (SimpleXmlRegistry::isView($entry)) {
            return self::viewElements($entry);
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
        // php-src sxe_object_cast_ex(IS_STRING/IS_LONG/IS_DOUBLE) → get_first_node (resets on <8.4; #27717).
        self::maybeImplicitlyResetIterator($entry);
        if (SimpleXmlRegistry::isAttributeNodeView($entry)) {
            $name = SimpleXmlRegistry::attributeNodeName($entry);
            $owner = SimpleXmlRegistry::state($entry);

            return $owner->attributes[$name] ?? '';
        }
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            $matches = self::namedChildViewElements($entry);
            if ([] === $matches) {
                return '';
            }

            // php-src: (string)$sxe->name uses the first matching sibling only.
            return $matches[0]->text;
        }
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            $parts = [];
            foreach (self::childrenViewElements($entry) as $node) {
                $parts[] = $node->text;
            }

            return implode('', $parts);
        }
        if (SimpleXmlRegistry::isView($entry)) {
            $parts = [];
            foreach (self::viewElements($entry) as $node) {
                $parts[] = $node->text;
            }

            return implode('', $parts);
        }

        return SimpleXmlRegistry::state($entry)->text;
    }

    /** @return list<SimpleXmlNodeState> */
    private static function matchingElements(ObjectEntry $entry, string $name): array
    {
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            // Nested `$sxe->a->b` resolves under the first match only (php-src sxe.c).
            $matches = self::namedChildViewElements($entry);
            if ([] === $matches) {
                return [];
            }

            return $matches[0]->elementsNamed($name);
        }
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            // Match local name among the namespace-filtered children (php-src sxe.c; #22728).
            $out = [];
            foreach (self::childrenViewElements($entry) as $node) {
                if (self::localNameFromQualified($node->name) === $name) {
                    $out[] = $node;
                }
            }

            return $out;
        }
        if (SimpleXmlRegistry::isView($entry)) {
            $out = [];
            foreach (self::viewElements($entry) as $node) {
                if ($node->name === $name) {
                    $out[] = $node;
                }
            }

            return $out;
        }

        return SimpleXmlRegistry::state($entry)->elementsNamed($name);
    }

    /**
     * Parent node for `$sxe->child` property access (php-src sxe get_property_ptr_ptr).
     *
     * Named / multi-match views nest under the first current match.
     */
    private static function propertyAccessParent(ObjectEntry $entry): SimpleXmlNodeState
    {
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            $matches = self::namedChildViewElements($entry);

            return $matches[0] ?? new SimpleXmlNodeState('');
        }
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            // Live children() views share the parent element — `$ch->name` selects among
            // that parent's children (php-src sxe.c; #21667), not under the first child.
            return SimpleXmlRegistry::state($entry);
        }
        if (SimpleXmlRegistry::isView($entry)) {
            $els = self::viewElements($entry);

            return $els[0] ?? new SimpleXmlNodeState('');
        }
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return new SimpleXmlNodeState('');
        }

        return SimpleXmlRegistry::state($entry);
    }

    /**
     * Materialize a live SimpleXMLElement for (array)/get_object_vars nested values (#21666).
     *
     * Context is unused (registry attach only); kept private wrapNode signature for call sites.
     */
    public static function wrapNodeForExport(
        ClassEntry $class,
        SimpleXmlNodeState $node,
        ?int $documentKey = null
    ): ObjectEntry {
        return self::wrapNode(null, $class, $node, $documentKey);
    }

    private static function wrapNode(?Context $ctx, ClassEntry $class, SimpleXmlNodeState $node, ?int $documentKey = null): ObjectEntry
    {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $docKey = $documentKey ?? $entry->id;
        SimpleXmlRegistry::attach($entry, $node, $docKey);

        return $entry;
    }

    /**
     * Attribute dimension / property handle — php-src sxe_prop_dim_read returns a live SXE
     * bound to the owning element's attribute slot (#19351, #22733, #22654).
     *
     * `$name` is the attributesMap / offset key (local name on NS-filtered views).
     */
    public static function wrapAttributeNode(
        Context $ctx,
        ObjectEntry $parent,
        string $name
    ): ObjectEntry {
        $owner = self::attributeOwnerState($parent);
        $storageKey = self::attributeStorageKey($parent, $owner, $name);
        $entry = new ObjectEntry($parent->class);
        $entry->constructed = true;
        SimpleXmlRegistry::attachAttributeNodeView(
            $entry,
            $owner,
            $storageKey,
            SimpleXmlRegistry::documentKey($parent)
        );

        return $entry;
    }

    /**
     * Map an attributesMap / offset key to the owner's stored attribute name.
     * NS-filtered views expose local names while the element stores `prefix:local` (#19554).
     */
    private static function attributeStorageKey(
        ObjectEntry $parent,
        SimpleXmlNodeState $owner,
        string $mapKey
    ): string {
        if (!SimpleXmlRegistry::isAttributesView($parent)) {
            return $mapKey;
        }
        if (\array_key_exists($mapKey, $owner->attributes)) {
            return $mapKey;
        }
        $filter = SimpleXmlRegistry::attributesViewFilter($parent);
        $ns = $filter['ns'];
        if (null === $ns || '' === $ns) {
            return $mapKey;
        }
        // Same prefix/URI resolution as filterAttributesByNamespace.
        $namespaces = self::namespaceMapForEntry($parent);
        $isPrefix = $filter['isPrefix'];
        $targetUri = $isPrefix ? ($namespaces[$ns] ?? $ns) : $ns;
        $matchedPrefix = null;
        if ($isPrefix && isset($namespaces[$ns])) {
            $matchedPrefix = $ns;
        } else {
            foreach ($namespaces as $prefix => $uri) {
                if ($uri === $targetUri) {
                    $matchedPrefix = $prefix;
                    break;
                }
            }
        }
        foreach ($owner->attributes as $qname => $_) {
            if (str_starts_with($qname, 'xmlns')) {
                continue;
            }
            $colon = strpos($qname, ':');
            if (false === $colon) {
                continue;
            }
            $attrPrefix = substr($qname, 0, $colon);
            $localName = substr($qname, $colon + 1);
            if ($localName !== $mapKey) {
                continue;
            }
            if (null !== $matchedPrefix && $attrPrefix === $matchedPrefix) {
                return $qname;
            }
            if (null === $matchedPrefix) {
                $attrUri = $namespaces[$attrPrefix] ?? '';
                if ($attrUri === $targetUri) {
                    return $qname;
                }
            }
        }

        return $mapKey;
    }

    /** Element node that owns the attributes map for `$parent` context. */
    private static function attributeOwnerState(ObjectEntry $parent): SimpleXmlNodeState
    {
        if (SimpleXmlRegistry::isNamedChildView($parent)) {
            $matches = self::namedChildViewElements($parent);
            if ([] !== $matches) {
                return $matches[0];
            }
        }

        return SimpleXmlRegistry::state($parent);
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

    private static function wrapNamedChildView(
        Context $ctx,
        ClassEntry $class,
        SimpleXmlNodeState $parent,
        string $childName,
        ?int $documentKey = null,
        ?array $childrenFilter = null
    ): ObjectEntry {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $docKey = $documentKey ?? $entry->id;
        SimpleXmlRegistry::attachNamedChildView($entry, $parent, $childName, $docKey, $childrenFilter);

        return $entry;
    }

    private static function wrapChildrenView(
        Context $ctx,
        ClassEntry $class,
        SimpleXmlNodeState $parent,
        ?int $documentKey = null,
        ?string $namespaceOrPrefix = null,
        bool $isPrefix = true
    ): ObjectEntry {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $docKey = $documentKey ?? $entry->id;
        SimpleXmlRegistry::attachChildrenView($entry, $parent, $docKey, $namespaceOrPrefix, $isPrefix);

        return $entry;
    }

    /**
     * Elements represented by a collection view (named property, frozen multi-match, or live children(); #20331/#20483).
     *
     * @return list<SimpleXmlNodeState>
     */
    public static function viewElements(ObjectEntry $entry): array
    {
        if (SimpleXmlRegistry::isNamedChildView($entry)) {
            return self::namedChildViewElements($entry);
        }
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            return self::childrenViewElements($entry);
        }

        return SimpleXmlRegistry::view($entry);
    }

    /**
     * Live `$sxe->name` property selection (php-src sxe.c; #20483).
     *
     * @return list<SimpleXmlNodeState>
     */
    public static function namedChildViewElements(ObjectEntry $entry): array
    {
        $parent = SimpleXmlRegistry::state($entry);
        $name = SimpleXmlRegistry::namedChildViewName($entry);
        $filter = SimpleXmlRegistry::namedChildViewFilter($entry);
        if (null === $filter) {
            return $parent->elementsNamed($name);
        }

        // Inherited children() NS filter: match local name within the filtered set
        // (php-src sxe_prop_dim_read; #22728 / #22829).
        $out = [];
        foreach (self::filterParentChildren($entry, $parent, $filter) as $node) {
            if (self::localNameFromQualified($node->name) === $name) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * Resolve live children() view elements from the shared parent node (php-src sxe.c; #20331).
     *
     * @return list<SimpleXmlNodeState>
     */
    public static function childrenViewElements(ObjectEntry $entry): array
    {
        $parent = SimpleXmlRegistry::state($entry);
        $filter = SimpleXmlRegistry::childrenViewFilter($entry);

        return self::filterParentChildren($entry, $parent, $filter);
    }

    /**
     * Apply a children() namespace filter to a parent node's direct children.
     *
     * @param array{ns: ?string, isPrefix: bool} $filter
     *
     * @return list<SimpleXmlNodeState>
     */
    private static function filterParentChildren(
        ObjectEntry $entry,
        SimpleXmlNodeState $parent,
        array $filter
    ): array {
        $elements = $parent->children;
        $namespaceOrPrefix = $filter['ns'];
        // php-src sxe_children: null or '' ⇒ unprefixed element children only (default xmlns
        // still included; prefixed QNames excluded). Non-empty ⇒ URI/prefix filter (#22737).
        if (null === $namespaceOrPrefix || '' === $namespaceOrPrefix) {
            return self::filterUnprefixedElementChildren($elements);
        }

        return self::filterChildrenByNamespace(
            $elements,
            $namespaceOrPrefix,
            $filter['isPrefix'],
            $entry,
            self::inScopeNamespacesForEntry($entry)
        );
    }

    /**
     * children()/children('') — element children whose QName has no prefix (php-src sxe.c).
     *
     * Default-xmlns nodes stay included; `prefix:local` nodes are excluded (#22737).
     *
     * @param list<SimpleXmlNodeState> $elements
     *
     * @return list<SimpleXmlNodeState>
     */
    private static function filterUnprefixedElementChildren(array $elements): array
    {
        return array_values(array_filter(
            $elements,
            static fn (SimpleXmlNodeState $element): bool => false === strpos($element->name, ':')
        ));
    }

    /**
     * Pre-8.4 php-src php_sxe_get_first_node: when iter.type != SXE_ITER_NONE, reset the
     * iterator (php_sxe_reset_iterator). PHP 8.4+ uses the non-destructive variant so
     * asXML()/getName()/casts no longer rewind mid-foreach (UPGRADING / #27717).
     *
     * Our named-child / children() / attributes() / multi-match views map to non-NONE
     * iter types; plain element nodes are SXE_ITER_NONE and must not rewind.
     */
    private static function maybeImplicitlyResetIterator(ObjectEntry $entry): void
    {
        if (version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')) {
            return;
        }
        if (!SimpleXmlRegistry::isView($entry) && !SimpleXmlRegistry::isAttributesView($entry)) {
            return;
        }
        SimpleXmlIteratorStorage::rewind($entry);
    }

    /** Detached xpath/node handles throw like php-src "not properly initialized" (#20483). */
    private static function assertNodeInitialized(ObjectEntry $entry, string $label): void
    {
        if (SimpleXmlRegistry::isView($entry)
            || SimpleXmlRegistry::isAttributesView($entry)
            || SimpleXmlRegistry::isAttributeNodeView($entry)) {
            return;
        }
        if (SimpleXmlRegistry::state($entry)->detached) {
            throw new \Error('SimpleXMLElement is not properly initialized');
        }
    }

    private static function wrapAttributesView(
        Context $ctx,
        ClassEntry $class,
        SimpleXmlNodeState $state,
        ?int $documentKey = null,
        ?string $namespaceOrPrefix = null,
        bool $isPrefix = true
    ): ObjectEntry {
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $docKey = $documentKey ?? $entry->id;
        SimpleXmlRegistry::attachAttributesView($entry, $state, $docKey, $namespaceOrPrefix, $isPrefix);

        return $entry;
    }

    /**
     * Attribute name=>value map for an element or live attributes() view (php-src sxe.c; #20332).
     *
     * @return array<string, string>
     */
    public static function attributesMap(ObjectEntry $entry): array
    {
        $state = SimpleXmlRegistry::state($entry);
        if (!SimpleXmlRegistry::isAttributesView($entry)) {
            return $state->attributes;
        }
        $filter = SimpleXmlRegistry::attributesViewFilter($entry);
        $ns = $filter['ns'];
        if (null !== $ns && '' !== $ns) {
            return self::filterAttributesByNamespace($state->attributes, $ns, $filter['isPrefix'], $entry);
        }

        return self::filterUnqualifiedAttributes($state->attributes);
    }

    private static function serializeNode(SimpleXmlNodeState $node): string
    {
        $attrs = '';
        foreach ($node->attributes as $name => $value) {
            $attrs .= sprintf(' %s="%s"', $name, self::escapeXmlAttribute($value));
        }
        $inner = self::serializeMixedContent($node);
        if ('' === $inner) {
            return sprintf('<%s%s/>', $node->name, $attrs);
        }

        return sprintf('<%s%s>%s</%s>', $node->name, $attrs, $inner, $node->name);
    }

    /**
     * xmlNodeDump order: text and element children interleaved (php-src sxe.c / libxml; #31049).
     */
    private static function serializeMixedContent(SimpleXmlNodeState $node): string
    {
        if ([] !== $node->content) {
            $inner = '';
            foreach ($node->content as $part) {
                if (\is_string($part)) {
                    $inner .= self::escapeXmlText($part);
                } else {
                    $inner .= self::serializeNode($part);
                }
            }

            return $inner;
        }
        $inner = self::escapeXmlText($node->text);
        foreach ($node->children as $child) {
            $inner .= self::serializeNode($child);
        }

        return $inner;
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
        if (SimpleXmlRegistry::isChildrenView($entry)) {
            return self::childrenViewElements($entry);
        }
        if (SimpleXmlRegistry::isView($entry)) {
            return self::viewElements($entry);
        }
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return [];
        }

        return [SimpleXmlRegistry::state($entry)];
    }

    /**
     * In-scope xmlns plus registerXPathNamespace bindings (php-src xmlGetNsList + xmlXPathRegisterNs).
     *
     * @return array<string, string> prefix => URI
     */
    private static function xpathNamespaceMap(ObjectEntry $entry): array
    {
        return array_merge(
            self::inScopeNamespacesForEntry($entry),
            SimpleXmlRegistry::xpathNamespaces($entry)
        );
    }

    /**
     * Resolve an XPath NameTest to local name + namespace URI.
     * Unprefixed names select the null namespace (XPath 1.0). Unknown prefix → null.
     *
     * @param array<string, string> $namespaces
     *
     * @return array{0: string, 1: string}|null [localName, namespaceUri] or null if prefix undefined
     */
    private static function resolveXPathQName(string $qName, array $namespaces): ?array
    {
        if ('*' === $qName) {
            return ['*', '*'];
        }
        if (!str_contains($qName, ':')) {
            return [$qName, ''];
        }
        [$prefix, $local] = explode(':', $qName, 2);
        if (!isset($namespaces[$prefix])) {
            return null;
        }

        return [$local, $namespaces[$prefix]];
    }

    /**
     * @param array<string, string> $parentScope
     *
     * @return array<string, string>
     */
    private static function scopeAfterNode(SimpleXmlNodeState $node, array $parentScope): array
    {
        $scope = $parentScope;
        foreach ($node->attributes as $name => $value) {
            if ('xmlns' === $name) {
                $scope[''] = $value;
            } elseif (str_starts_with($name, 'xmlns:')) {
                $scope[substr($name, 6)] = $value;
            }
        }

        return $scope;
    }

    /**
     * @param array<string, string> $parentScope xmlns in scope on the parent (before this node's decls)
     */
    private static function nodeMatchesQName(
        SimpleXmlNodeState $node,
        string $localName,
        string $namespaceUri,
        array $parentScope
    ): bool {
        $nodeScope = self::scopeAfterNode($node, $parentScope);
        $nodeLocal = self::localNameFromQualified($node->name);
        if ('*' !== $localName && $nodeLocal !== $localName) {
            return false;
        }
        if ('*' === $namespaceUri) {
            return true;
        }
        $nodeUri = self::resolveElementNamespaceUri($node, $nodeScope);

        return $nodeUri === $namespaceUri;
    }

    /**
     * Document-order descendants-or-self matching local name + namespace URI.
     *
     * @param array<string, string> $parentScope
     *
     * @return list<SimpleXmlNodeState>
     */
    private static function collectDescendantsByQName(
        SimpleXmlNodeState $node,
        string $localName,
        string $namespaceUri,
        array $parentScope
    ): array {
        $out = [];
        $nodeScope = self::scopeAfterNode($node, $parentScope);
        if (self::nodeMatchesQName($node, $localName, $namespaceUri, $parentScope)) {
            $out[] = $node;
        }
        foreach ($node->children as $child) {
            foreach (self::collectDescendantsByQName($child, $localName, $namespaceUri, $nodeScope) as $match) {
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
        ObjectEntry $entry,
        ?array $parentScope = null
    ): array {
        $namespaces = $parentScope ?? self::inScopeNamespacesForEntry($entry);
        $map = array_merge($namespaces, SimpleXmlRegistry::xpathNamespaces($entry));
        // php-src: unknown prefix with isPrefix=true falls back to URI match (same as children()).
        $targetUri = $isPrefix ? ($map[$namespaceOrPrefix] ?? $namespaceOrPrefix) : $namespaceOrPrefix;
        $out = [];
        foreach ($elements as $element) {
            $elementUri = self::resolveElementNamespaceUri($element, $namespaces);
            if ($elementUri === $targetUri) {
                $out[] = $element;
            }
        }

        return $out;
    }

    /** @return array<string, string> prefix => namespace URI in scope on $entry's node(s) */
    private static function inScopeNamespacesForEntry(ObjectEntry $entry): array
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return self::namespaceMapForEntry($entry);
        }

        $docKey = SimpleXmlRegistry::documentKey($entry);
        $root = SimpleXmlRegistry::rootState($docKey);
        // Live children() / named-child views share the parent element — use that node for
        // xmlns scope (#20331). Do not call viewElements() here: named-child resolution
        // re-enters filterParentChildren → inScopeNamespacesForEntry (#22728).
        if (SimpleXmlRegistry::isChildrenView($entry) || SimpleXmlRegistry::isNamedChildView($entry)) {
            $nodes = [SimpleXmlRegistry::state($entry)];
        } else {
            $nodes = SimpleXmlRegistry::isView($entry) ? self::viewElements($entry) : [SimpleXmlRegistry::state($entry)];
        }
        $merged = [];
        foreach ($nodes as $node) {
            $scope = self::namespacesAtNodeWalk($root, $node, []);
            if (null === $scope) {
                continue;
            }
            foreach ($scope as $prefix => $uri) {
                $merged[$prefix] = $uri;
            }
        }

        return $merged;
    }

    /** @param array<string, string> $inScope */
    private static function namespacesAtNodeWalk(SimpleXmlNodeState $node, SimpleXmlNodeState $target, array $inScope): ?array
    {
        $scope = $inScope;
        foreach ($node->attributes as $name => $value) {
            if ('xmlns' === $name) {
                $scope[''] = $value;
            } elseif (str_starts_with($name, 'xmlns:')) {
                $scope[substr($name, 6)] = $value;
            }
        }
        if ($node === $target) {
            return $scope;
        }
        foreach ($node->children as $child) {
            $found = self::namespacesAtNodeWalk($child, $target, $scope);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /** Namespace URI for an element name in the given in-scope xmlns map (php-src sxe_children). */
    private static function resolveElementNamespaceUri(SimpleXmlNodeState $element, array $inScope): string
    {
        if (isset($element->attributes['xmlns'])) {
            return $element->attributes['xmlns'];
        }
        $colon = strpos($element->name, ':');
        if (false !== $colon) {
            $prefix = substr($element->name, 0, $colon);
            if ('' !== $prefix) {
                if (isset($element->attributes['xmlns:'.$prefix])) {
                    return $element->attributes['xmlns:'.$prefix];
                }

                return $inScope[$prefix] ?? '';
            }
        }

        return $inScope[''] ?? '';
    }

    /** @param array<string, string> $attributes */
    private static function filterUnqualifiedAttributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $name => $value) {
            if (str_starts_with($name, 'xmlns')) {
                continue;
            }
            if (str_contains($name, ':')) {
                continue;
            }
            $out[$name] = $value;
        }

        return $out;
    }

    /** @return array<string, string> prefix => namespace URI */
    private static function namespaceMapForEntry(ObjectEntry $entry): array
    {
        $map = SimpleXmlRegistry::xpathNamespaces($entry);
        if (SimpleXmlRegistry::isAttributesView($entry) || SimpleXmlRegistry::isChildrenView($entry)) {
            // Live attributes/children views share the element node — read xmlns from that state.
            self::collectRegisteredNamespacesFromNode(SimpleXmlRegistry::state($entry), $map, false);

            return $map;
        }
        $nodes = SimpleXmlRegistry::isView($entry) ? self::viewElements($entry) : [SimpleXmlRegistry::state($entry)];
        foreach ($nodes as $node) {
            self::collectRegisteredNamespacesFromNode($node, $map, false);
        }

        return $map;
    }

    /** @param array<string, string> $attributes */
    private static function filterAttributesByNamespace(
        array $attributes,
        string $namespaceOrPrefix,
        bool $isPrefix,
        ObjectEntry $entry
    ): array {
        $namespaces = self::namespaceMapForEntry($entry);
        // php-src: unknown prefix with isPrefix=true falls back to URI match (same as children()).
        $targetUri = $isPrefix ? ($namespaces[$namespaceOrPrefix] ?? $namespaceOrPrefix) : $namespaceOrPrefix;
        $matchedPrefix = null;
        if ($isPrefix && isset($namespaces[$namespaceOrPrefix])) {
            $matchedPrefix = $namespaceOrPrefix;
        } else {
            foreach ($namespaces as $prefix => $uri) {
                if ($uri === $targetUri) {
                    $matchedPrefix = $prefix;
                    break;
                }
            }
        }

        $out = [];
        foreach ($attributes as $name => $value) {
            if (str_starts_with($name, 'xmlns')) {
                continue;
            }
            $colon = strpos($name, ':');
            if (false === $colon) {
                continue;
            }
            $attrPrefix = substr($name, 0, $colon);
            $localName = substr($name, $colon + 1);
            if (null !== $matchedPrefix && $attrPrefix === $matchedPrefix) {
                $out[$localName] = $value;
                continue;
            }
            if (null === $matchedPrefix) {
                $attrUri = $namespaces[$attrPrefix] ?? '';
                if ($attrUri === $targetUri) {
                    $out[$localName] = $value;
                }
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

    /**
     * getDocNamespaces — xmlns declarations (php-src sxe_add_registered_namespaces).
     *
     * @return array<string, string>
     */
    private static function collectRegisteredNamespaces(ObjectEntry $entry, bool $recursive, bool $fromRoot): array
    {
        if (SimpleXmlRegistry::isAttributesView($entry) || SimpleXmlRegistry::isAttributeNodeView($entry)) {
            return [];
        }

        $nodes = [];
        if ($fromRoot) {
            $nodes = [SimpleXmlRegistry::rootState(SimpleXmlRegistry::documentKey($entry))];
        } elseif (SimpleXmlRegistry::isChildrenView($entry) || SimpleXmlRegistry::isNamedChildView($entry)) {
            $nodes = [SimpleXmlRegistry::state($entry)];
        } elseif (SimpleXmlRegistry::isView($entry)) {
            $nodes = self::viewElements($entry);
        } else {
            $nodes = [SimpleXmlRegistry::state($entry)];
        }

        $out = [];
        foreach ($nodes as $node) {
            self::collectRegisteredNamespacesFromNode($node, $out, $recursive);
        }

        return $out;
    }

    /**
     * getNamespaces — NS used by element/attrs (php-src sxe_add_namespaces; #22729).
     *
     * @return array<string, string>
     */
    private static function collectUsedNamespaces(ObjectEntry $entry, bool $recursive): array
    {
        if (SimpleXmlRegistry::isAttributesView($entry)) {
            return [];
        }

        if (SimpleXmlRegistry::isAttributeNodeView($entry)) {
            $out = [];
            $name = SimpleXmlRegistry::attributeNodeName($entry);
            $colon = strpos($name, ':');
            if (false === $colon) {
                return [];
            }
            $prefix = substr($name, 0, $colon);
            if ('' === $prefix) {
                return [];
            }
            $owner = SimpleXmlRegistry::state($entry);
            $docKey = SimpleXmlRegistry::documentKey($entry);
            $root = SimpleXmlRegistry::rootState($docKey);
            $parentScope = self::parentScopeForNode($root, $owner) ?? [];
            $nodeScope = self::scopeAfterNode($owner, $parentScope);
            if (isset($owner->attributes['xmlns:'.$prefix])) {
                $out[$prefix] = $owner->attributes['xmlns:'.$prefix];
            } elseif (isset($nodeScope[$prefix])) {
                $out[$prefix] = $nodeScope[$prefix];
            }

            return $out;
        }

        $docKey = SimpleXmlRegistry::documentKey($entry);
        $root = SimpleXmlRegistry::rootState($docKey);

        if (SimpleXmlRegistry::isChildrenView($entry) || SimpleXmlRegistry::isNamedChildView($entry)) {
            $nodes = self::directElementChildren($entry);
        } elseif (SimpleXmlRegistry::isView($entry)) {
            $nodes = self::viewElements($entry);
        } else {
            $nodes = [SimpleXmlRegistry::state($entry)];
        }

        $out = [];
        foreach ($nodes as $node) {
            $parentScope = self::parentScopeForNode($root, $node) ?? [];
            self::collectUsedNamespacesFromNode($node, $parentScope, $out, $recursive);
        }

        return $out;
    }

    /**
     * Scope in effect on the parent of $target (before $target's own xmlns decls).
     *
     * @param array<string, string> $inScope
     *
     * @return array<string, string>|null
     */
    private static function parentScopeForNode(SimpleXmlNodeState $root, SimpleXmlNodeState $target): ?array
    {
        return self::parentScopeForNodeWalk($root, $target, []);
    }

    /**
     * php-src / libxml xmlSearchNsByHref — first xmlns declaration for $href on $from
     * or an ancestor (declaration order on each node). Returns prefix ('' = default) or null.
     */
    private static function searchNsPrefixByHref(SimpleXmlNodeState $root, SimpleXmlNodeState $from, string $href): ?string
    {
        $chain = self::ancestorChainInclusive($root, $from);
        if (null === $chain) {
            return null;
        }
        for ($i = \count($chain) - 1; $i >= 0; --$i) {
            foreach ($chain[$i]->attributes as $name => $value) {
                if ($value !== $href) {
                    continue;
                }
                if ('xmlns' === $name) {
                    return '';
                }
                if (str_starts_with($name, 'xmlns:')) {
                    return substr($name, 6);
                }
            }
        }

        return null;
    }

    /**
     * Path from $root to $target inclusive, or null if $target is not in the tree.
     *
     * @return list<SimpleXmlNodeState>|null
     */
    private static function ancestorChainInclusive(SimpleXmlNodeState $root, SimpleXmlNodeState $target): ?array
    {
        if ($root === $target) {
            return [$root];
        }

        return self::ancestorChainInclusiveWalk($root, $target, [$root]);
    }

    /**
     * @param list<SimpleXmlNodeState> $path
     *
     * @return list<SimpleXmlNodeState>|null
     */
    private static function ancestorChainInclusiveWalk(SimpleXmlNodeState $node, SimpleXmlNodeState $target, array $path): ?array
    {
        foreach ($node->children as $child) {
            $next = $path;
            $next[] = $child;
            if ($child === $target) {
                return $next;
            }
            $found = self::ancestorChainInclusiveWalk($child, $target, $next);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $inScope
     *
     * @return array<string, string>|null
     */
    private static function parentScopeForNodeWalk(SimpleXmlNodeState $node, SimpleXmlNodeState $target, array $inScope): ?array
    {
        if ($node === $target) {
            return $inScope;
        }
        $scope = self::scopeAfterNode($node, $inScope);
        foreach ($node->children as $child) {
            $found = self::parentScopeForNodeWalk($child, $target, $scope);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $parentScope
     * @param array<string, string> $out
     */
    private static function collectUsedNamespacesFromNode(
        SimpleXmlNodeState $node,
        array $parentScope,
        array &$out,
        bool $recursive
    ): void {
        $nodeScope = self::scopeAfterNode($node, $parentScope);
        $uri = self::resolveElementNamespaceUri($node, $parentScope);
        if ('' !== $uri) {
            $prefix = self::elementNamespacePrefix($node);
            if (!\array_key_exists($prefix, $out)) {
                $out[$prefix] = $uri;
            }
        }

        foreach ($node->attributes as $name => $value) {
            if ('xmlns' === $name || str_starts_with($name, 'xmlns:')) {
                continue;
            }
            $colon = strpos($name, ':');
            if (false === $colon) {
                continue;
            }
            $prefix = substr($name, 0, $colon);
            if ('' === $prefix || \array_key_exists($prefix, $out)) {
                continue;
            }
            if (isset($node->attributes['xmlns:'.$prefix])) {
                $out[$prefix] = $node->attributes['xmlns:'.$prefix];
            } elseif (isset($nodeScope[$prefix])) {
                $out[$prefix] = $nodeScope[$prefix];
            }
        }

        if ($recursive) {
            foreach ($node->children as $child) {
                self::collectUsedNamespacesFromNode($child, $nodeScope, $out, true);
            }
        }
    }

    private static function elementNamespacePrefix(SimpleXmlNodeState $node): string
    {
        $colon = strpos($node->name, ':');
        if (false === $colon) {
            return '';
        }

        return substr($node->name, 0, $colon);
    }

    /** @param array<string, string> $out */
    private static function collectRegisteredNamespacesFromNode(SimpleXmlNodeState $node, array &$out, bool $recursive): void
    {
        foreach ($node->attributes as $name => $value) {
            if ('xmlns' === $name) {
                if (!\array_key_exists('', $out)) {
                    $out[''] = $value;
                }
            } elseif (str_starts_with($name, 'xmlns:')) {
                $prefix = substr($name, 6);
                if (!\array_key_exists($prefix, $out)) {
                    $out[$prefix] = $value;
                }
            }
        }
        if ($recursive) {
            foreach ($node->children as $child) {
                self::collectRegisteredNamespacesFromNode($child, $out, true);
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
            // CDATA coalesces into element text (php-src sxe.c / libxml; #19710).
            $cdata = VmXml::parseCdataSectionAt($inner, $pos);
            if (null !== $cdata) {
                $textBuffer .= $cdata['data'];
                $pos = $cdata['end'];

                continue;
            }
            // Comments are skipped (not part of SimpleXML text content).
            $comment = VmXml::parseCommentAt($inner, $pos);
            if (null !== $comment) {
                $pos = $comment['end'];

                continue;
            }
            if ('' !== $textBuffer) {
                $node->appendText($textBuffer);
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
            $node->appendElement($child);
            $pos = $end;
        }
        $node->appendText($textBuffer);

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
            if ('<' === $content[$scan]) {
                // Skip CDATA / comments so nested markup inside them is not
                // mistaken for element boundaries (php-src libxml; #19710).
                $cdata = VmXml::parseCdataSectionAt($content, $scan);
                if (null !== $cdata) {
                    $scan = $cdata['end'];

                    continue;
                }
                $comment = VmXml::parseCommentAt($content, $scan);
                if (null !== $comment) {
                    $scan = $comment['end'];

                    continue;
                }
            }
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
