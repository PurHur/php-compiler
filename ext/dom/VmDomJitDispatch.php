<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableObject;

/**
 * DOM JIT/AOT dispatch bodies — separate TU so nested JIT compiles only invokeArgv (#17130).
 */
final class VmDomJitDispatch
{
    /**
     * @param list<Variable> $extra
     */
    public static function loadHTML(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExtraArgCountRange('DOMDocument::loadHTML', $extra, 1, 2);
        // Z_PARAM_STR: strict null → TypeError; weak → DEP + '' → ValueError (#30041 / #22680).
        $html = self::stringArg(
            $extra[0] ?? self::missingArg('loadHTML', 0),
            'DOMDocument::loadHTML',
            0,
            'source'
        );
        $options = 0;
        if (isset($extra[1])) {
            // Z_PARAM_LONG $options — no Frame in JIT helper; soft-coerce like non-strict (#25768).
            $options = VmMath::parseZParamLongBuiltinArg(
                $extra[1],
                'DOMDocument::loadHTML',
                2,
                'options'
            );
        }
        $ok = VmDom::loadHTML($ctx, $document, $html, $options);
        $var = new Variable();
        $var->bool($ok);

        return $var;
    }

    /**
     * DOMDocument::loadHTMLFile() — user arity 1–2 (#30835; php-src document.c).
     *
     * @param list<Variable> $extra
     */
    public static function loadHTMLFile(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExtraArgCountRange('DOMDocument::loadHTMLFile', $extra, 1, 2);
        $filename = self::stringArg(
            $extra[0] ?? self::missingArg('loadHTMLFile', 0),
            'DOMDocument::loadHTMLFile',
            0,
            'filename'
        );
        $options = 0;
        if (isset($extra[1])) {
            $options = VmMath::parseZParamLongBuiltinArg(
                $extra[1],
                'DOMDocument::loadHTMLFile',
                2,
                'options'
            );
        }
        $ok = VmDom::loadHTMLFile($ctx, $document, $filename, $options);
        $var = new Variable();
        $var->bool($ok);

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function loadXML(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExtraArgCountRange('DOMDocument::loadXML', $extra, 1, 2);
        // Z_PARAM_STR: strict null → TypeError; weak → DEP + '' → ValueError (#30041 / #22680).
        $xml = self::stringArg(
            $extra[0] ?? self::missingArg('loadXML', 0),
            'DOMDocument::loadXML',
            0,
            'source'
        );
        $options = 0;
        if (isset($extra[1])) {
            $options = VmMath::parseZParamLongBuiltinArg(
                $extra[1],
                'DOMDocument::loadXML',
                2,
                'options'
            );
        }
        $ok = VmDom::loadXML($ctx, $document, $xml, null, $options);
        $var = new Variable();
        $var->bool($ok);

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function load(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExtraArgCountRange('DOMDocument::load', $extra, 1, 2);
        $filename = VmString::coerceStringBuiltinArg(
            ($extra[0] ?? self::missingArg('load', 0))->resolveIndirect(),
            'DOMDocument::load',
            0,
            'filename'
        );
        $options = 0;
        if (isset($extra[1])) {
            $options = VmMath::parseZParamLongBuiltinArg(
                $extra[1],
                'DOMDocument::load',
                2,
                'options'
            );
        }
        $ok = VmDom::load($ctx, $document, $filename, $options);
        $var = new Variable();
        $var->bool($ok);

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function saveXML(ObjectEntry $document, array $extra): Variable
    {
        self::requireAtMostExtraArgCount('DOMDocument::saveXML', $extra, 2);
        $node = self::optionalSaveDomNodeArg(
            $extra[0] ?? null,
            'DOMDocument::saveXML',
            1,
            'node'
        );
        $options = 0;
        if (isset($extra[1])) {
            $options = VmMath::parseZParamLongBuiltinArg(
                $extra[1],
                'DOMDocument::saveXML',
                2,
                'options'
            );
        }
        $xml = VmDom::saveXML($document, $node, $options);
        $var = new Variable();
        $var->string($xml);

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function getElementById(ObjectEntry $document, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMDocument::getElementById', $extra, 1);
        // Full method label + elementId for Zend-shaped TypeError under strict_types (#29942).
        $id = self::stringArg(
            $extra[0] ?? self::missingArg('getElementById', 0),
            'DOMDocument::getElementById',
            0,
            'elementId'
        );
        $found = VmDom::getElementById($document, $id);
        $var = new Variable();
        if (null === $found) {
            $var->null();
        } else {
            $var->object($found);
        }

        return $var;
    }

    /**
     * Dom\Document::querySelector() — php-src parentnode.c (#19580, #29453).
     *
     * @param list<Variable> $extra
     */
    public static function querySelector(ObjectEntry $document, array $extra): Variable
    {
        $selectors = self::stringArg($extra[0] ?? self::missingArg('querySelector', 0), 'querySelector', 0);
        $found = VmDomLiving::querySelector($document, $selectors);
        $var = new Variable();
        if (null === $found) {
            $var->null();
        } else {
            $var->object($found);
        }

        return $var;
    }

    /**
     * Dom\Document::querySelectorAll() — php-src parentnode.c (#19580, #29453).
     *
     * @param list<Variable> $extra
     */
    public static function querySelectorAll(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        $selectors = self::stringArg(
            $extra[0] ?? self::missingArg('querySelectorAll', 0),
            'querySelectorAll',
            0
        );

        return VmDomLiving::querySelectorAll($ctx, $document, $selectors);
    }

    /**
     * Dom\Element::closest() — php-src php_dom.c (#20418).
     *
     * @param list<Variable> $extra
     */
    public static function closest(ObjectEntry $element, array $extra): Variable
    {
        $selectors = self::stringArg($extra[0] ?? self::missingArg('closest', 0), 'closest', 0);
        $found = VmDomLiving::closest($element, $selectors);
        $var = new Variable();
        if (null === $found) {
            $var->null();
        } else {
            $var->object($found);
        }

        return $var;
    }

    /**
     * Dom\Element::matches() — php-src php_dom.c (#20418).
     *
     * @param list<Variable> $extra
     */
    public static function matches(ObjectEntry $element, array $extra): Variable
    {
        $selectors = self::stringArg($extra[0] ?? self::missingArg('matches', 0), 'matches', 0);
        $var = new Variable();
        $var->bool(VmDomLiving::matches($element, $selectors));

        return $var;
    }

    /**
     * Dom\HTMLDocument::saveHtml() — php-src html_document.c (#19580).
     *
     * @param list<Variable> $extra
     */
    public static function saveHtml(ObjectEntry $document, array $extra): Variable
    {
        self::requireAtMostExtraArgCount('DOMDocument::saveHTML', $extra, 1);
        $node = self::optionalSaveDomNodeArg(
            $extra[0] ?? null,
            'DOMDocument::saveHTML',
            1,
            'node'
        );
        $var = new Variable();
        $var->string(VmDomLiving::saveHtml($document, $node));

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function createElement(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExtraArgCountRange('DOMDocument::createElement', $extra, 1, 2);
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('createElement', 0),
            'DOMDocument::createElement',
            0,
            'localName'
        );
        $value = isset($extra[1])
            ? self::stringArg($extra[1], 'DOMDocument::createElement', 1, 'value')
            : '';

        return VmDom::createElement($ctx, $name, $document, $value);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function createAttribute(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMDocument::createAttribute', $extra, 1);
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('createAttribute', 0),
            'DOMDocument::createAttribute',
            0,
            'localName'
        );

        return VmDom::createAttribute($ctx, $name, $document);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function createAttributeNS(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMDocument::createAttributeNS', $extra, 2);
        $namespace = self::nullableStringArg(
            $extra[0] ?? self::missingArg('createAttributeNS', 0),
            'createAttributeNS',
            0
        );
        $qualifiedName = self::stringArg(
            $extra[1] ?? self::missingArg('createAttributeNS', 1),
            'createAttributeNS',
            1
        );

        return VmDom::createAttributeNS($ctx, $namespace, $qualifiedName, $document);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function normalize(VmContext $ctx, ObjectEntry $node, array $extra): Variable
    {
        if (\count($extra) > 0) {
            throw new \ArgumentCountError(
                'DOMNode::normalize() expects exactly 0 arguments, '.\count($extra).' given'
            );
        }
        $canonical = DomRegistry::entry($node->id) ?? $node;
        VmDom::normalizeLiveStandard($ctx, $canonical);
        if ($canonical !== $node) {
            VmDom::mirrorNodeLinkProperties($node, $canonical);
        }
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function normalizeDocument(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMDocument::normalizeDocument', $extra, 0);
        VmDom::normalizeDocument($ctx, $document);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * DOMNode::hasAttributes() — exact user arity 0 (#31011; php-src node.c).
     *
     * @param list<Variable> $extra
     */
    public static function hasAttributes(ObjectEntry $node, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMNode::hasAttributes', $extra, 0);
        $var = new Variable();
        $var->bool(VmDom::hasAttributes($node));

        return $var;
    }

    /**
     * DOMNode::getNodePath() — exact user arity 0 (#31011; php-src node.c).
     *
     * @param list<Variable> $extra
     */
    public static function getNodePath(ObjectEntry $node, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMNode::getNodePath', $extra, 0);
        $path = VmDom::getNodePath($node);
        $var = new Variable();
        if (null === $path) {
            $var->null();
        } else {
            $var->string($path);
        }

        return $var;
    }

    /**
     * DOMElement::getElementsByTagName() — exact user arity 1 (#31011; php-src element.c).
     *
     * @param list<Variable> $extra
     */
    public static function getElementsByTagNameFromElement(
        VmContext $ctx,
        ObjectEntry $element,
        array $extra
    ): Variable {
        self::requireExactExtraArgCount('DOMElement::getElementsByTagName', $extra, 1);
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('getElementsByTagName', 0),
            'DOMElement::getElementsByTagName',
            0,
            'qualifiedName'
        );

        return VmDom::getElementsByTagNameFromNode($ctx, $element, $name);
    }

    /**
     * DOMDocument / DOMElement::getElementsByTagName() (#31011).
     *
     * @param list<Variable> $extra
     */
    public static function dispatchGetElementsByTagName(
        VmContext $ctx,
        ObjectEntry $receiver,
        array $extra
    ): Variable {
        if (VmDom::isDocument($receiver)) {
            self::requireExactExtraArgCount('DOMDocument::getElementsByTagName', $extra, 1);
            $name = self::stringArg(
                $extra[0] ?? self::missingArg('getElementsByTagName', 0),
                'DOMDocument::getElementsByTagName',
                0,
                'qualifiedName'
            );

            return VmDom::getElementsByTagName($ctx, $receiver, $name);
        }
        if (VmDom::isElement($receiver)) {
            return self::getElementsByTagNameFromElement($ctx, $receiver, $extra);
        }

        throw new \Error('Call to undefined method '.$receiver->class->name.'::getElementsByTagName()');
    }

    /**
     * DOMNodeList::count() — exact user arity 0 (#31011; php-src nodelist.c).
     *
     * @param list<Variable> $extra
     */
    public static function nodeListCount(ObjectEntry $nodeList, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMNodeList::count', $extra, 0);
        $var = new Variable();
        $var->int(VmDom::nodeListCount($nodeList));

        return $var;
    }

    /**
     * Countable::count() on NodeList / NamedNodeMap / TokenList (#31011).
     *
     * @param list<Variable> $extra
     */
    public static function dispatchCount(ObjectEntry $receiver, array $extra): Variable
    {
        if (VmDom::isNodeList($receiver)) {
            return self::nodeListCount($receiver, $extra);
        }
        if (VmDom::isNamedNodeMap($receiver)) {
            self::requireExactExtraArgCount('DOMNamedNodeMap::count', $extra, 0);
            $var = new Variable();
            $var->int($receiver->getProperty(VmDom::PROP_LENGTH)->resolveIndirect()->toInt());

            return $var;
        }
        if (VmDom::isTokenList($receiver)) {
            self::requireExactExtraArgCount('DOMTokenList::count', $extra, 0);
            $var = new Variable();
            $var->int(VmDomTokenList::length($receiver));

            return $var;
        }

        throw new \Error('Call to undefined method '.$receiver->class->name.'::count()');
    }

    /**
     * @param list<Variable> $extra
     */
    public static function appendChild(VmContext $ctx, ObjectEntry $parent, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMNode::appendChild', $extra, 1);
        $child = VariableObject::entry($extra[0] ?? self::missingArg('appendChild', 0));

        return VmDom::appendChildVariable($ctx, $parent, $child);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function append(VmContext $ctx, ObjectEntry $parent, array $extra): Variable
    {
        VmDom::appendLiveStandardNodes($ctx, $parent, $extra);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function prepend(VmContext $ctx, ObjectEntry $parent, array $extra): Variable
    {
        VmDom::prependLiveStandardNodes($ctx, $parent, $extra);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function after(VmContext $ctx, ObjectEntry $node, array $extra): Variable
    {
        VmDom::afterLiveStandardNodes($ctx, $node, $extra);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function before(VmContext $ctx, ObjectEntry $node, array $extra): Variable
    {
        VmDom::beforeLiveStandardNodes($ctx, $node, $extra);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function replaceWith(VmContext $ctx, ObjectEntry $node, array $extra): Variable
    {
        VmDom::replaceWithLiveStandardNodes($ctx, $node, $extra);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * ChildNode::remove vs DOMTokenList::remove (#26752).
     *
     * @param list<Variable> $extra
     */
    public static function dispatchRemove(VmContext $ctx, ObjectEntry $receiver, array $extra): Variable
    {
        return match (strtolower($receiver->class->name)) {
            'domtokenlist', 'dom\\tokenlist' => self::tokenListRemove($ctx, $receiver, $extra),
            default => self::childNodeRemove($ctx, $receiver, $extra),
        };
    }

    /**
     * ChildNode::remove — exact user arity 0 (#30814; php_dom.stub.php).
     *
     * @param list<Variable> $extra
     */
    public static function childNodeRemove(VmContext $ctx, ObjectEntry $node, array $extra): Variable
    {
        self::requireExactExtraArgCount(DomClassMethod::childNodeRemoveFunction($node), $extra, 0);
        VmDom::removeLiveStandard($ctx, $node);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function replaceChildren(VmContext $ctx, ObjectEntry $parent, array $extra): Variable
    {
        VmDom::replaceChildrenLiveStandardNodes($ctx, $parent, $extra);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function importNode(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExtraArgCountRange('DOMDocument::importNode', $extra, 1, 2);
        $node = VariableObject::entry($extra[0] ?? self::missingArg('importNode', 0));
        $deep = false;
        if (isset($extra[1])) {
            $deepVar = $extra[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $deepVar->type) {
                $deep = $deepVar->toBool();
            } elseif (Variable::TYPE_INTEGER === $deepVar->type) {
                $deep = 0 !== $deepVar->toInt();
            } else {
                $label = VmDomLiving::isLivingDocument($document)
                    ? 'Dom\\Document::importNode()'
                    : 'DOMDocument::importNode()';
                throw new \TypeError($label.': Argument #2 ($deep) must be of type bool');
            }
        }

        return VmDom::importNode($ctx, $document, $node, $deep);
    }

    /**
     * Dom\Document::importLegacyNode() — JIT/AOT instance invoke (#20940).
     *
     * @param list<Variable> $extra
     */
    public static function importLegacyNode(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        $node = VariableObject::entry($extra[0] ?? self::missingArg('importLegacyNode', 0));
        $deep = false;
        if (isset($extra[1])) {
            $deepVar = $extra[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $deepVar->type) {
                $deep = $deepVar->toBool();
            } elseif (Variable::TYPE_INTEGER === $deepVar->type) {
                $deep = 0 !== $deepVar->toInt();
            } else {
                throw new \TypeError(
                    'Dom\\Document::importLegacyNode(): Argument #2 ($deep) must be of type bool'
                );
            }
        }

        return VmDom::importLegacyNode($ctx, $document, $node, $deep);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function adoptNode(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMDocument::adoptNode', $extra, 1);
        $node = VariableObject::entry($extra[0] ?? self::missingArg('adoptNode', 0));

        return VmDom::adoptNode($ctx, $document, $node);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function createDocumentFragment(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMDocument::createDocumentFragment', $extra, 0);

        return VmDom::createDocumentFragment($ctx, $document);
    }

    /**
     * DOMImplementation::createDocumentType() — php-src ext/dom/domimplementation.stub.php (#19797).
     *
     * @param list<Variable> $extra
     */
    public static function createDocumentType(VmContext $ctx, ObjectEntry $implementation, array $extra): Variable
    {
        unset($implementation);
        $argc = \count($extra);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'DOMImplementation::createDocumentType() expects at least 1 argument, 0 given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'DOMImplementation::createDocumentType() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $qualifiedName = self::stringArg(
            $extra[0],
            'DOMImplementation::createDocumentType',
            0,
            'qualifiedName'
        );
        $publicId = isset($extra[1])
            ? self::stringArg($extra[1], 'DOMImplementation::createDocumentType', 1, 'publicId')
            : '';
        $systemId = isset($extra[2])
            ? self::stringArg($extra[2], 'DOMImplementation::createDocumentType', 2, 'systemId')
            : '';

        return VmDom::createDocumentType($ctx, $qualifiedName, $publicId, $systemId);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function hasAttribute(ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::hasAttribute', $extra, 1);
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('hasAttribute', 0),
            'DOMElement::hasAttribute',
            0,
            'qualifiedName'
        );
        $var = new Variable();
        $var->bool(VmDom::hasAttribute($element, $name));

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function hasAttributeNS(ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::hasAttributeNS', $extra, 2);
        $namespace = self::nullableStringArg(
            $extra[0] ?? self::missingArg('hasAttributeNS', 0),
            'hasAttributeNS',
            0
        );
        $localName = self::stringArg(
            $extra[1] ?? self::missingArg('hasAttributeNS', 1),
            'DOMElement::hasAttributeNS',
            1,
            'localName'
        );
        $var = new Variable();
        $var->bool(VmDom::hasAttributeNS($element, $namespace, $localName));

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function getAttribute(ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::getAttribute', $extra, 1);
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('getAttribute', 0),
            'DOMElement::getAttribute',
            0,
            'qualifiedName'
        );
        $value = VmDom::getAttribute($element, $name);
        $var = new Variable();
        if (null === $value) {
            $var->null();
        } else {
            $var->string($value);
        }

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function getAttributeNS(ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::getAttributeNS', $extra, 2);
        $namespace = self::nullableStringArg($extra[0] ?? self::missingArg('getAttributeNS', 0), 'getAttributeNS', 0);
        $localName = self::stringArg(
            $extra[1] ?? self::missingArg('getAttributeNS', 1),
            'DOMElement::getAttributeNS',
            1,
            'localName'
        );
        $value = VmDom::getAttributeNS($element, $namespace, $localName);
        $var = new Variable();
        if (null === $value) {
            $var->null();
        } else {
            $var->string($value);
        }

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function getAttributeNode(VmContext $ctx, ObjectEntry $element, array $extra): Variable
    {
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('getAttributeNode', 0),
            'DOMElement::getAttributeNode',
            0,
            'qualifiedName'
        );

        return VmDom::getAttributeNode($ctx, $element, $name);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function setAttribute(VmContext $ctx, ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::setAttribute', $extra, 2);
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('setAttribute', 0),
            'DOMElement::setAttribute',
            0,
            'qualifiedName'
        );
        $value = self::stringArg(
            $extra[1] ?? self::missingArg('setAttribute', 1),
            'DOMElement::setAttribute',
            1,
            'value'
        );
        // php-src element.c — name_len == 0 → ValueError (#24480).
        VmDom::rejectEmptyQualifiedName($name, 'DOMElement::setAttribute', 1);
        // php-src DOM_RET_OBJ / xmlns → true (#24538).
        return VmDom::setAttribute($ctx, $element, $name, $value);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function removeAttribute(VmContext $ctx, ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::removeAttribute', $extra, 1);
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('removeAttribute', 0),
            'DOMElement::removeAttribute',
            0,
            'qualifiedName'
        );
        $var = new Variable();
        $var->bool(VmDom::removeAttributeNS($ctx, $element, null, $name));

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function getAttributeNodeNS(VmContext $ctx, ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::getAttributeNodeNS', $extra, 2);
        $namespace = self::nullableStringArg($extra[0] ?? self::missingArg('getAttributeNodeNS', 0), 'getAttributeNodeNS', 0);
        $localName = self::stringArg(
            $extra[1] ?? self::missingArg('getAttributeNodeNS', 1),
            'DOMElement::getAttributeNodeNS',
            1,
            'localName'
        );

        return VmDom::getAttributeNodeNS($ctx, $element, $namespace, $localName);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function setAttributeNodeNS(VmContext $ctx, ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::setAttributeNodeNS', $extra, 1);
        $attrVar = ($extra[0] ?? self::missingArg('setAttributeNodeNS', 0))->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $attrVar->type) {
            throw new \TypeError('DOMElement::setAttributeNodeNS(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $attr = VariableObject::entry($attrVar);
        if (!VmDom::isAttr($attr)) {
            throw new \TypeError('DOMElement::setAttributeNodeNS(): Argument #1 ($attr) must be of type DOMAttr');
        }

        return VmDom::setAttributeNodeNS($ctx, $element, $attr);
    }

    /**
     * DOMElement::setIdAttribute() — JIT/AOT (#20129, php-src ext/dom/node.c).
     *
     * @param list<Variable> $extra
     */
    public static function setIdAttribute(ObjectEntry $element, array $extra): Variable
    {
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('setIdAttribute', 0),
            'DOMElement::setIdAttribute',
            0,
            'qualifiedName'
        );
        $isId = self::optionalBoolArg(
            $extra[1] ?? self::missingArg('setIdAttribute', 1),
            'setIdAttribute',
            1
        );
        VmDom::setIdAttribute($element, $name, $isId);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * DOMElement::setIdAttributeNS() — JIT/AOT (#20129, php-src ext/dom/element.c).
     *
     * @param list<Variable> $extra
     */
    public static function setIdAttributeNS(ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::setIdAttributeNS', $extra, 3);
        // php-src stub: string $namespace (not ?string) + string $qualifiedName (#30091).
        $namespace = self::stringArg(
            $extra[0] ?? self::missingArg('setIdAttributeNS', 0),
            'DOMElement::setIdAttributeNS',
            0,
            'namespace'
        );
        $localName = self::stringArg(
            $extra[1] ?? self::missingArg('setIdAttributeNS', 1),
            'DOMElement::setIdAttributeNS',
            1,
            'qualifiedName'
        );
        $isId = self::optionalBoolArg(
            $extra[2] ?? self::missingArg('setIdAttributeNS', 2),
            'setIdAttributeNS',
            2
        );
        VmDom::setIdAttributeNS($element, $namespace, $localName, $isId);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * DOMElement::setIdAttributeNode() — JIT/AOT (#20123, php-src ext/dom/element.c).
     *
     * @param list<Variable> $extra
     */
    public static function setIdAttributeNode(ObjectEntry $element, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMElement::setIdAttributeNode', $extra, 2);
        $attrVar = ($extra[0] ?? self::missingArg('setIdAttributeNode', 0))->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $attrVar->type) {
            throw new \TypeError('DOMElement::setIdAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $attr = VariableObject::entry($attrVar);
        if (!VmDom::isAttr($attr)) {
            throw new \TypeError('DOMElement::setIdAttributeNode(): Argument #1 ($attr) must be of type DOMAttr');
        }
        $isId = self::optionalBoolArg(
            $extra[1] ?? self::missingArg('setIdAttributeNode', 1),
            'setIdAttributeNode',
            1
        );
        VmDom::setIdAttributeNode($element, $attr, $isId);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * DOMAttr::isId() — JIT/AOT (#20129, php-src ext/dom/attr.c).
     *
     * @param list<Variable> $extra
     */
    public static function attrIsId(ObjectEntry $attr, array $extra): Variable
    {
        if (\count($extra) > 0) {
            throw new \ArgumentCountError(
                'DOMAttr::isId() expects exactly 0 arguments, '.\count($extra).' given'
            );
        }
        $result = new Variable();
        $result->bool(VmDom::attrIsId($attr));

        return $result;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function tokenListAdd(VmContext $ctx, ObjectEntry $tokenList, array $extra): Variable
    {
        VmDomTokenList::add($ctx, $tokenList, $extra);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function tokenListRemove(VmContext $ctx, ObjectEntry $tokenList, array $extra): Variable
    {
        VmDomTokenList::remove($ctx, $tokenList, $extra);
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function tokenListContains(ObjectEntry $tokenList, array $extra): Variable
    {
        $token = self::stringArg($extra[0] ?? self::missingArg('contains', 0), 'contains', 0);
        $var = new Variable();
        $var->bool(VmDomTokenList::contains($tokenList, $token));

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function dispatchItem(VmContext $ctx, ObjectEntry $receiver, array $extra): Variable
    {
        return match (strtolower($receiver->class->name)) {
            'domtokenlist', 'dom\\tokenlist' => self::tokenListItem($receiver, $extra),
            'domnodelist' => self::nodeListItem($ctx, $receiver, $extra),
            'domnamednodemap', 'dom\\namednodemap' => self::namedNodeMapItem($receiver, $extra),
            default => throw new \Error('Call to undefined method '.$receiver->class->name.'::item()'),
        };
    }

    /**
     * DOMNamedNodeMap::item() — index Attr lookup (#30835; php-src namednodemap.c).
     *
     * @param list<Variable> $extra
     */
    public static function namedNodeMapItem(ObjectEntry $namedNodeMap, array $extra): Variable
    {
        if (!VmDom::isNamedNodeMap($namedNodeMap)) {
            throw new \Error('Call to undefined method '.$namedNodeMap->class->name.'::item()');
        }
        self::requireExactExtraArgCount('DOMNamedNodeMap::item', $extra, 1);
        $indexVar = ($extra[0] ?? self::missingArg('item', 0))->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $indexVar->type && Variable::TYPE_FLOAT !== $indexVar->type) {
            throw new \TypeError(sprintf(
                'DOMNamedNodeMap::item(): Argument #1 ($index) must be of type int, %s given',
                VmDom::typeLabel($indexVar)
            ));
        }
        $index = $indexVar->toInt();
        $result = new Variable();
        if ($index < 0) {
            $result->null();

            return $result;
        }
        $node = VmDom::namedNodeMapItem($namedNodeMap, $index);
        if (null === $node) {
            $result->null();
        } else {
            $result->object($node);
        }

        return $result;
    }

    /**
     * DOMNamedNodeMap::getNamedItem() — local-name Attr lookup (php-src namednodemap.c; #24332).
     *
     * @param list<Variable> $extra
     */
    public static function namedNodeMapGetNamedItem(ObjectEntry $namedNodeMap, array $extra): Variable
    {
        if (!VmDom::isNamedNodeMap($namedNodeMap)) {
            throw new \Error('Call to undefined method '.$namedNodeMap->class->name.'::getNamedItem()');
        }
        self::requireExactExtraArgCount('DOMNamedNodeMap::getNamedItem', $extra, 1);
        $name = self::stringArg(
            $extra[0] ?? self::missingArg('getNamedItem', 0),
            'DOMNamedNodeMap::getNamedItem',
            0,
            'qualifiedName'
        );
        $result = new Variable();
        $node = VmDom::namedNodeMapGetNamedItem($namedNodeMap, $name);
        if (null === $node) {
            $result->null();
        } else {
            $result->object($node);
        }

        return $result;
    }

    /**
     * DOMNamedNodeMap::getNamedItemNS() — JIT/AOT (#17515, #24332).
     *
     * @param list<Variable> $extra
     */
    public static function namedNodeMapGetNamedItemNS(ObjectEntry $namedNodeMap, array $extra): Variable
    {
        if (!VmDom::isNamedNodeMap($namedNodeMap)) {
            throw new \Error('Call to undefined method '.$namedNodeMap->class->name.'::getNamedItemNS()');
        }
        $namespace = self::nullableStringArg(
            $extra[0] ?? self::missingArg('getNamedItemNS', 0),
            'DOMNamedNodeMap::getNamedItemNS',
            0
        );
        $localName = self::stringArg(
            $extra[1] ?? self::missingArg('getNamedItemNS', 1),
            'DOMNamedNodeMap::getNamedItemNS',
            1,
            'localName'
        );
        $result = new Variable();
        $node = VmDom::namedNodeMapGetNamedItemNS($namedNodeMap, $namespace, $localName);
        if (null === $node) {
            $result->null();
        } else {
            $result->object($node);
        }

        return $result;
    }

    /**
     * IteratorAggregate::getIterator() — NodeList / NamedNodeMap / TokenList (#21298, #20884).
     *
     * @param list<Variable> $extra
     */
    public static function dispatchGetIterator(VmContext $ctx, ObjectEntry $receiver, array $extra): Variable
    {
        $result = new Variable();
        if (VmDom::isNodeList($receiver)) {
            $result->object(VmDom::nodeListGetIterator($ctx, $receiver));

            return $result;
        }
        if (VmDom::isNamedNodeMap($receiver)) {
            $result->object(VmDom::namedNodeMapGetIterator($ctx, $receiver));

            return $result;
        }
        if (VmDom::isTokenList($receiver)) {
            $result->object(VmDomTokenList::getIterator($ctx, $receiver));

            return $result;
        }

        throw new \Error('Call to undefined method '.$receiver->class->name.'::getIterator()');
    }

    /**
     * @param list<Variable> $extra
     */
    public static function nodeListItem(VmContext $ctx, ObjectEntry $nodeList, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMNodeList::item', $extra, 1);
        $indexVar = ($extra[0] ?? self::missingArg('item', 0))->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $indexVar->type && Variable::TYPE_FLOAT !== $indexVar->type) {
            throw new \TypeError(sprintf(
                'DOMNodeList::item(): Argument #1 ($index) must be of type int, %s given',
                VmDom::typeLabel($indexVar)
            ));
        }
        $index = $indexVar->toInt();
        $result = new Variable();
        if ($index < 0) {
            $result->null();

            return $result;
        }
        $node = VmDom::nodeListItem($nodeList, $index);
        if (null === $node) {
            $result->null();
        } else {
            $result->object($node);
        }

        return $result;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function tokenListItem(ObjectEntry $tokenList, array $extra): Variable
    {
        $indexVar = ($extra[0] ?? self::missingArg('item', 0))->resolveIndirect();
        $item = VmDomTokenList::item($tokenList, $indexVar->toInt());
        $result = new Variable();
        if (null === $item) {
            $result->null();
        } else {
            $result->string($item);
        }

        return $result;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function tokenListToggle(VmContext $ctx, ObjectEntry $tokenList, array $extra): Variable
    {
        $token = self::stringArg($extra[0] ?? self::missingArg('toggle', 0), 'toggle', 0);
        $force = null;
        if (isset($extra[1])) {
            $forceVar = $extra[1]->resolveIndirect();
            $force = Variable::TYPE_NULL === $forceVar->type ? null : $forceVar->toBool();
        }
        $result = new Variable();
        $result->bool(VmDomTokenList::toggle($ctx, $tokenList, $token, $force));

        return $result;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function dispatchContains(ObjectEntry $receiver, array $extra): Variable
    {
        return match (strtolower($receiver->class->name)) {
            'domtokenlist', 'dom\\tokenlist' => self::tokenListContains($receiver, $extra),
            default => self::nodeContains($receiver, $extra),
        };
    }

    /**
     * @param list<Variable> $extra
     */
    public static function nodeContains(ObjectEntry $node, array $extra): Variable
    {
        $otherVar = ($extra[0] ?? self::missingArg('contains', 0))->resolveIndirect();
        $other = null;
        if (Variable::TYPE_NULL !== $otherVar->type) {
            $other = VariableObject::entry($otherVar);
        }
        $result = new Variable();
        $result->bool(VmDom::contains($node, $other));

        return $result;
    }

    /**
     * Dom\Attr::rename / Dom\Element::rename — php-src element.c (#21083, #27108).
     *
     * @param list<Variable> $extra
     */
    public static function rename(VmContext $ctx, ObjectEntry $receiver, array $extra): Variable
    {
        if (\count($extra) !== 2) {
            $label = VmDom::isAttr($receiver) ? 'Dom\\Attr::rename()' : 'Dom\\Element::rename()';
            throw new \ArgumentCountError(sprintf(
                '%s expects exactly 2 arguments, %d given',
                $label,
                \count($extra)
            ));
        }
        $namespaceUri = self::nullableStringArg($extra[0], 'rename', 0);
        $qualifiedName = self::stringArg($extra[1], 'rename', 1);
        if (VmDom::isAttr($receiver)) {
            VmDomLiving::renameAttr($ctx, $receiver, $namespaceUri, $qualifiedName);
        } elseif (VmDom::isElement($receiver)) {
            VmDomLiving::renameElement($receiver, $namespaceUri, $qualifiedName);
        } else {
            throw new \Error('Call to undefined method '.$receiver->class->name.'::rename()');
        }
        $null = new Variable();
        $null->null();

        return $null;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function toggleAttribute(VmContext $ctx, ObjectEntry $element, array $extra): Variable
    {
        $name = self::stringArg($extra[0] ?? self::missingArg('toggleAttribute', 0), 'toggleAttribute', 0);
        $force = null;
        if (isset($extra[1])) {
            $forceVar = $extra[1]->resolveIndirect();
            if (Variable::TYPE_NULL === $forceVar->type) {
                $force = null;
            } elseif (Variable::TYPE_BOOLEAN === $forceVar->type) {
                $force = $forceVar->toBool();
            } else {
                throw new \TypeError(
                    'DOMElement::toggleAttribute(): Argument #2 ($force) must be of type ?bool, '
                    .VmDom::typeLabel($forceVar).' given'
                );
            }
        }
        $var = new Variable();
        $var->bool(VmDom::toggleAttribute($ctx, $element, $name, $force));

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function getRootNode(ObjectEntry $node, array $extra): Variable
    {
        unset($extra);
        $result = new Variable();
        $result->object(VmDom::getRootNode($node));

        return $result;
    }

    /**
     * DOMDocument::createTextNode() — AOT bridge for normalize merge repros (#20642).
     *
     * @param list<Variable> $extra
     */
    public static function createTextNode(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMDocument::createTextNode', $extra, 1);
        $data = self::stringArg(
            $extra[0] ?? self::missingArg('createTextNode', 0),
            'DOMDocument::createTextNode',
            0,
            'data'
        );
        $result = new Variable();
        $result->object(VmDom::createTextNode($ctx, $data, $document));

        return $result;
    }

    /**
     * DOMText::splitText() — AOT/JIT (php-src ext/dom/text.c xmlTextSplitText) (#32362).
     *
     * @param list<Variable> $extra
     */
    public static function splitText(VmContext $ctx, ObjectEntry $node, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMText::splitText', $extra, 1);
        $offsetVar = ($extra[0] ?? self::missingArg('splitText', 0))->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $offsetVar->type && Variable::TYPE_FLOAT !== $offsetVar->type) {
            throw new \TypeError(sprintf(
                'DOMText::splitText(): Argument #1 ($offset) must be of type int, %s given',
                VmDom::typeLabel($offsetVar)
            ));
        }
        $tail = VmDom::textSplitText($ctx, $node, $offsetVar->toInt());
        $result = new Variable();
        if (null === $tail) {
            $result->bool(false);

            return $result;
        }
        $result->object($tail);

        return $result;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function isEqualNode(ObjectEntry $node, array $extra): Variable
    {
        $arg = ($extra[0] ?? self::missingArg('isEqualNode', 0))->resolveIndirect();
        $result = new Variable();
        // php-src stub ?DOMNode — null other → false (#24462, ext/dom/node.c).
        if (Variable::TYPE_NULL === $arg->type) {
            $result->bool(false);

            return $result;
        }
        $other = VariableObject::entry($arg);
        $result->bool(VmDom::isEqualNode($node, $other));

        return $result;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function xpathQuery(VmContext $ctx, ObjectEntry $xpath, array $extra): Variable
    {
        self::requireExtraArgCountRange('DOMXPath::query', $extra, 1, 3);
        $expression = self::stringArg(
            $extra[0] ?? self::missingArg('query', 0),
            'DOMXPath::query',
            0,
            'expression'
        );
        $contextNode = self::optionalDomNodeArg($extra[1] ?? null, 'query', 1);
        // php-src: omitted 3rd arg uses intern->register_node_ns (default true) (#20842).
        $registerNodeNS = \array_key_exists(2, $extra)
            ? self::optionalBoolArg($extra[2], 'query', 2)
            : DomRegistry::state($xpath)->xpathRegisterNodeNamespaces;

        return VmDomXPath::query($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function xpathEvaluate(VmContext $ctx, ObjectEntry $xpath, array $extra): Variable
    {
        self::requireExtraArgCountRange('DOMXPath::evaluate', $extra, 1, 3);
        $expression = self::stringArg(
            $extra[0] ?? self::missingArg('evaluate', 0),
            'DOMXPath::evaluate',
            0,
            'expression'
        );
        $contextNode = self::optionalDomNodeArg($extra[1] ?? null, 'evaluate', 1);
        // php-src: omitted 3rd arg uses intern->register_node_ns (default true) (#20842).
        $registerNodeNS = \array_key_exists(2, $extra)
            ? self::optionalBoolArg($extra[2], 'evaluate', 2)
            : DomRegistry::state($xpath)->xpathRegisterNodeNamespaces;

        return VmDomXPath::evaluate($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function xpathRegisterNamespace(ObjectEntry $xpath, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMXPath::registerNamespace', $extra, 2);
        // Z_PARAM_STR param names from php_dom.stub.php (#30301, sibling #30041).
        $prefix = self::stringArg(
            $extra[0] ?? self::missingArg('registerNamespace', 0),
            'DOMXPath::registerNamespace',
            0,
            'prefix'
        );
        $namespaceUri = self::stringArg(
            $extra[1] ?? self::missingArg('registerNamespace', 1),
            'DOMXPath::registerNamespace',
            1,
            'namespace'
        );
        $result = new Variable();
        $result->bool(VmDomXPath::registerNamespace($xpath, $prefix, $namespaceUri));

        return $result;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function xpathRegisterPhpFunctions(ObjectEntry $xpath, array $extra): Variable
    {
        self::requireAtMostExtraArgCount('DOMXPath::registerPhpFunctions', $extra, 1);
        VmDomXPath::registerPhpFunctions($xpath, $extra[0] ?? null);
        $result = new Variable(Variable::TYPE_NULL);
        $result->null();

        return $result;
    }

    /**
     * DOMXPath::registerPhpFunctionNS() — php-src ext/dom/xpath.c (#20119).
     *
     * @param list<Variable> $extra
     */
    public static function xpathRegisterPhpFunctionNS(VmContext $ctx, ObjectEntry $xpath, array $extra): Variable
    {
        $namespaceUri = self::stringArg($extra[0] ?? self::missingArg('registerPhpFunctionNS', 0), 'registerPhpFunctionNS', 0);
        $name = self::stringArg($extra[1] ?? self::missingArg('registerPhpFunctionNS', 1), 'registerPhpFunctionNS', 1);
        $callable = $extra[2] ?? self::missingArg('registerPhpFunctionNS', 2);
        VmDomXPath::registerPhpFunctionNS($ctx, $xpath, $namespaceUri, $name, $callable);
        $result = new Variable(Variable::TYPE_NULL);
        $result->null();

        return $result;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function compareDocumentPosition(ObjectEntry $node, array $extra): Variable
    {
        $otherVar = ($extra[0] ?? self::missingArg('compareDocumentPosition', 0))->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $otherVar->type) {
            throw new \TypeError(
                'DOMNode::compareDocumentPosition(): Argument #1 ($other) must be of type DOMNode, '
                .VmDom::typeLabel($otherVar).' given'
            );
        }
        $other = VariableObject::entry($otherVar);
        if (!VmDom::isDomNode($other)) {
            throw new \TypeError(
                'DOMNode::compareDocumentPosition(): Argument #1 ($other) must be of type DOMNode, '
                .VmDom::typeLabel($otherVar).' given'
            );
        }
        $var = new Variable();
        $var->int(VmDom::compareDocumentPosition($node, $other));

        return $var;
    }

    /**
     * DOMCharacterData::substringData() — php-src characterdata.c (#32372).
     *
     * @param list<Variable> $extra
     */
    public static function substringData(ObjectEntry $node, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMCharacterData::substringData', $extra, 2);
        $offsetVar = ($extra[0] ?? self::missingArg('substringData', 0))->resolveIndirect();
        $countVar = ($extra[1] ?? self::missingArg('substringData', 1))->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $offsetVar->type && Variable::TYPE_FLOAT !== $offsetVar->type) {
            throw new \TypeError(sprintf(
                'DOMCharacterData::substringData(): Argument #1 ($offset) must be of type int, %s given',
                VmDom::typeLabel($offsetVar)
            ));
        }
        if (Variable::TYPE_INTEGER !== $countVar->type && Variable::TYPE_FLOAT !== $countVar->type) {
            throw new \TypeError(sprintf(
                'DOMCharacterData::substringData(): Argument #2 ($count) must be of type int, %s given',
                VmDom::typeLabel($countVar)
            ));
        }
        $result = new Variable();
        $result->string(VmDom::characterDataSubstringData(
            $node,
            $offsetVar->toInt(),
            $countVar->toInt()
        ));

        return $result;
    }

    /**
     * DOMCharacterData::appendData() — php-src characterdata.c xmlTextConcat (#32376).
     *
     * @param list<Variable> $extra
     */
    public static function appendData(ObjectEntry $node, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMCharacterData::appendData', $extra, 1);
        if (!VmDom::isCharacterData($node)) {
            throw new \TypeError('DOMCharacterData::appendData() must be called on a character data node');
        }
        $arg = self::stringArg(
            $extra[0] ?? self::missingArg('appendData', 0),
            'DOMCharacterData::appendData',
            0,
            'data'
        );
        VmDom::characterDataAppendData($node, $arg);
        $result = new Variable();
        $result->bool(true);

        return $result;
    }

    /**
     * DOMCharacterData::insertData() — php-src characterdata.c xmlTextInsert (#32380).
     *
     * @param list<Variable> $extra
     */
    public static function insertData(ObjectEntry $node, array $extra): Variable
    {
        self::requireExactExtraArgCount('DOMCharacterData::insertData', $extra, 2);
        if (!VmDom::isCharacterData($node)) {
            throw new \TypeError('DOMCharacterData::insertData() must be called on a character data node');
        }
        $offsetVar = ($extra[0] ?? self::missingArg('insertData', 0))->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $offsetVar->type && Variable::TYPE_FLOAT !== $offsetVar->type) {
            throw new \TypeError(sprintf(
                'DOMCharacterData::insertData(): Argument #1 ($offset) must be of type int, %s given',
                VmDom::typeLabel($offsetVar)
            ));
        }
        $arg = self::stringArg(
            $extra[1] ?? self::missingArg('insertData', 1),
            'DOMCharacterData::insertData',
            1,
            'data'
        );
        VmDom::characterDataInsertData($node, $offsetVar->toInt(), $arg);
        $result = new Variable();
        $result->bool(true);

        return $result;
    }

    /**
     * DOMNode::C14N() — inclusive/exclusive canonical XML (#19467).
     *
     * @param list<Variable> $extra
     */
    public static function c14n(VmContext $ctx, ObjectEntry $node, array $extra): Variable
    {
        self::requireAtMostExtraArgCount('DOMNode::C14N', $extra, 4);
        $exclusive = self::optionalBoolArg($extra[0] ?? null, 'C14N', 0);
        $withComments = self::optionalBoolArg($extra[1] ?? null, 'C14N', 1);
        $xpath = null;
        if (isset($extra[2])) {
            $xpathVar = $extra[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $xpathVar->type) {
                if (Variable::TYPE_ARRAY !== $xpathVar->type) {
                    throw new \TypeError(
                        'DOMNode::C14N(): Argument #3 ($xpath) must be of type ?array, '
                        .VmDom::typeLabel($xpathVar).' given'
                    );
                }
                $xpath = NodeC14N::phpArrayFromVariable($xpathVar);
            }
        }
        $nsPrefixes = null;
        if (isset($extra[3])) {
            $nsVar = $extra[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $nsVar->type) {
                if (Variable::TYPE_ARRAY !== $nsVar->type) {
                    throw new \TypeError(
                        'DOMNode::C14N(): Argument #4 ($ns_prefixes) must be of type ?array, '
                        .VmDom::typeLabel($nsVar).' given'
                    );
                }
                $nsPrefixes = NodeC14N::phpArrayFromVariable($nsVar);
            }
        }
        $payload = VmDom::c14n($ctx, $node, $exclusive, $withComments, $xpath, $nsPrefixes, null, 'DOMNode::C14N');
        $result = new Variable();
        if (false === $payload) {
            $result->bool(false);
        } else {
            $result->string($payload);
        }

        return $result;
    }

    /**
     * @return list<Variable>
     */
    public static function unpackArgs(Variable $argsTable): array
    {
        $extra = [];
        $args = $argsTable->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $args->type) {
            return $extra;
        }
        $ht = $args->toArray();
        $limit = $ht->numElements;
        for ($i = 0; $i < $limit; ++$i) {
            $slot = $ht->findIndex($i);
            if (null !== $slot) {
                $extra[] = $slot->resolveIndirect();
            }
        }

        return $extra;
    }

    private static function stringArg(
        Variable $var,
        string $label,
        int $index,
        string $paramName = 'value'
    ): string {
        $frame = VmDomJitFrame::executingFrame();
        if (null !== $frame && InternalStrictArg::isCallerStrict($frame)) {
            $function = str_ends_with($label, '()') ? substr($label, 0, -2) : $label;
            InternalStrictArg::rejectNullString($var, $function, $paramName, $index, $frame);
        }

        return VmString::coerceStringBuiltinArg($var->resolveIndirect(), $label, $index, $paramName);
    }

    private static function nullableStringArg(Variable $var, string $label, int $index): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $label, $index, 'value');
    }


    /** Excess/missing user argc — Zend ArgumentCountError (#30616). $extra excludes $this. */
    private static function requireExactExtraArgCount(string $function, array $extra, int $expected): void
    {
        $given = \count($extra);
        if ($given !== $expected) {
            throw new \ArgumentCountError(DomClassMethod::exactUserArgCountMessage($function, $expected, $given));
        }
    }

    private static function requireAtMostExtraArgCount(string $function, array $extra, int $maximum): void
    {
        $given = \count($extra);
        if ($given > $maximum) {
            throw new \ArgumentCountError(DomClassMethod::atMostUserArgCountMessage($function, $maximum, $given));
        }
    }

    private static function requireExtraArgCountRange(string $function, array $extra, int $minimum, int $maximum): void
    {
        $given = \count($extra);
        if ($given < $minimum) {
            throw new \ArgumentCountError(DomClassMethod::atLeastUserArgCountMessage($function, $minimum, $given));
        }
        if ($given > $maximum) {
            throw new \ArgumentCountError(DomClassMethod::atMostUserArgCountMessage($function, $maximum, $given));
        }
    }

    private static function missingArg(string $method, int $index): Variable
    {
        throw new \ArgumentCountError($method.'() expects argument #'.($index + 1));
    }

    /**
     * Z_PARAM_OBJECT_OF_CLASS_OR_NULL(DOMNode) for saveXML/saveHTML (#31396).
     *
     * $function is Class::method without trailing "()".
     */
    private static function optionalSaveDomNodeArg(
        ?Variable $var,
        string $function,
        int $userArgIndex,
        string $paramName
    ): ?ObjectEntry {
        if (null === $var) {
            return null;
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type ?DOMNode, %s given',
                $function,
                $userArgIndex,
                $paramName,
                VmDom::typeLabel($var)
            ));
        }
        $object = VariableObject::entry($var);
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type ?DOMNode, %s given',
                $function,
                $userArgIndex,
                $paramName,
                $object->class->name
            ));
        }

        return $object;
    }

    private static function optionalDomNodeArg(?Variable $var, string $label, int $index): ?ObjectEntry
    {
        if (null === $var) {
            return null;
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                'DOMXPath::%s(): Argument #%d ($context) must be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        $object = VariableObject::entry($var);
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(sprintf(
                'DOMXPath::%s(): Argument #%d ($context) must be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }

    private static function optionalBoolArg(?Variable $var, string $label, int $index): bool
    {
        if (null === $var) {
            return false;
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(sprintf(
                'Argument #%d of %s() must be of type bool, %s given',
                $index + 1,
                $label,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toBool();
    }
}
