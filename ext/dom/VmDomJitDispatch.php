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
}
