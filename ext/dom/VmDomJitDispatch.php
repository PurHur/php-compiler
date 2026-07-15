<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Context as VmContext;
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
        $html = self::stringArg($extra[0] ?? self::missingArg('loadHTML', 0), 'loadHTML', 0);
        $options = 0;
        if (isset($extra[1])) {
            $optionsVar = $extra[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $optionsVar->type) {
                throw new \TypeError('DOMDocument::loadHTML(): Argument #2 ($options) must be of type int');
            }
            $options = $optionsVar->toInt();
        }
        $ok = VmDom::loadHTML($ctx, $document, $html, $options);
        $var = new Variable();
        $var->bool($ok);

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function getElementById(ObjectEntry $document, array $extra): Variable
    {
        $id = self::stringArg($extra[0] ?? self::missingArg('getElementById', 0), 'getElementById', 0);
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
     * @param list<Variable> $extra
     */
    public static function createElement(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        $name = self::stringArg($extra[0] ?? self::missingArg('createElement', 0), 'createElement', 0);
        $value = isset($extra[1]) ? self::stringArg($extra[1], 'createElement', 1) : '';

        return VmDom::createElement($ctx, $name, $document, $value);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function appendChild(VmContext $ctx, ObjectEntry $parent, array $extra): Variable
    {
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
        $node = VariableObject::entry($extra[0] ?? self::missingArg('importNode', 0));
        $deep = false;
        if (isset($extra[1])) {
            $deepVar = $extra[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $deepVar->type) {
                $deep = $deepVar->toBool();
            } elseif (Variable::TYPE_INTEGER === $deepVar->type) {
                $deep = 0 !== $deepVar->toInt();
            } else {
                throw new \TypeError('DOMDocument::importNode(): Argument #2 ($deep) must be of type bool');
            }
        }

        return VmDom::importNode($ctx, $document, $node, $deep);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function createDocumentFragment(VmContext $ctx, ObjectEntry $document, array $extra): Variable
    {
        return VmDom::createDocumentFragment($ctx, $document);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function getAttribute(ObjectEntry $element, array $extra): Variable
    {
        $name = self::stringArg($extra[0] ?? self::missingArg('getAttribute', 0), 'getAttribute', 0);
        $value = VmDom::getAttributeNS($element, null, $name);
        $var = new Variable();
        $var->string($value);

        return $var;
    }

    /**
     * @param list<Variable> $extra
     */
    public static function setAttribute(VmContext $ctx, ObjectEntry $element, array $extra): Variable
    {
        $name = self::stringArg($extra[0] ?? self::missingArg('setAttribute', 0), 'setAttribute', 0);
        $value = self::stringArg($extra[1] ?? self::missingArg('setAttribute', 1), 'setAttribute', 1);
        VmDom::setAttributeNS($ctx, $element, null, $name, $value);
        $null = new Variable();
        $null->null();

        return $null;
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
            'domtokenlist' => self::tokenListItem($receiver, $extra),
            'domnodelist' => self::nodeListItem($ctx, $receiver, $extra),
            default => throw new \Error('Call to undefined method '.$receiver->class->name.'::item()'),
        };
    }

    /**
     * @param list<Variable> $extra
     */
    public static function nodeListItem(VmContext $ctx, ObjectEntry $nodeList, array $extra): Variable
    {
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
    public static function xpathQuery(VmContext $ctx, ObjectEntry $xpath, array $extra): Variable
    {
        $expression = self::stringArg($extra[0] ?? self::missingArg('query', 0), 'query', 0);
        $contextNode = self::optionalDomNodeArg($extra[1] ?? null, 'query', 1);
        $registerNodeNS = self::optionalBoolArg($extra[2] ?? null, 'query', 2);

        return VmDomXPath::query($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function xpathEvaluate(VmContext $ctx, ObjectEntry $xpath, array $extra): Variable
    {
        $expression = self::stringArg($extra[0] ?? self::missingArg('evaluate', 0), 'evaluate', 0);
        $contextNode = self::optionalDomNodeArg($extra[1] ?? null, 'evaluate', 1);
        $registerNodeNS = self::optionalBoolArg($extra[2] ?? null, 'evaluate', 2);

        return VmDomXPath::evaluate($ctx, $xpath, $expression, $contextNode, $registerNodeNS);
    }

    /**
     * @param list<Variable> $extra
     */
    public static function xpathRegisterNamespace(ObjectEntry $xpath, array $extra): Variable
    {
        $prefix = self::stringArg($extra[0] ?? self::missingArg('registerNamespace', 0), 'registerNamespace', 0);
        $namespaceUri = self::stringArg($extra[1] ?? self::missingArg('registerNamespace', 1), 'registerNamespace', 1);
        $result = new Variable();
        $result->bool(VmDomXPath::registerNamespace($xpath, $prefix, $namespaceUri));

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
     * @return list<Variable>
     */
    public static function unpackArgs(Variable $argsTable): array
    {
        $extra = [];
        $args = $argsTable->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $args->type && Variable::TYPE_HASHTABLE !== $args->type) {
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

    private static function stringArg(Variable $var, string $label, int $index): string
    {
        return VmString::coerceStringBuiltinArg($var->resolveIndirect(), $label, $index, 'value');
    }

    private static function missingArg(string $method, int $index): Variable
    {
        throw new \ArgumentCountError($method.'() expects argument #'.($index + 1));
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
        if (Variable::TYPE_BOOL !== $var->type) {
            throw new \TypeError(sprintf(
                'DOMXPath::%s(): Argument #%d ($registerNodeNS) must be of type bool, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }

        return $var->bool;
    }
}
