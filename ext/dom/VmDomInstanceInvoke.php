<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VariableObject;

/**
 * DOM instance method dispatch for JIT/AOT helpers (#17130, #17391).
 *
 * Per-arity entrypoints avoid args-table unpacking in standalone AOT helper TUs.
 */
final class VmDomInstanceInvoke
{
    public static function invoke0Object(Variable $receiver, string $methodLc): Variable
    {
        return self::dispatch($receiver, $methodLc);
    }

    public static function invoke1Object(Variable $receiver, string $methodLc, Variable $a1): Variable
    {
        return self::dispatch($receiver, $methodLc, $a1);
    }

    public static function invoke2Object(
        Variable $receiver,
        string $methodLc,
        Variable $a1,
        Variable $a2
    ): Variable {
        return self::dispatch($receiver, $methodLc, $a1, $a2);
    }

    public static function invoke3Object(
        Variable $receiver,
        string $methodLc,
        Variable $a1,
        Variable $a2,
        Variable $a3
    ): Variable {
        return self::dispatch($receiver, $methodLc, $a1, $a2, $a3);
    }

    public static function invoke4Object(
        Variable $receiver,
        string $methodLc,
        Variable $a1,
        Variable $a2,
        Variable $a3,
        Variable $a4
    ): Variable {
        return self::dispatch($receiver, $methodLc, $a1, $a2, $a3, $a4);
    }

    private static function dispatch(Variable $receiver, string $methodLc, Variable ...$extra): Variable
    {
        $self = VariableObject::entry($receiver->resolveIndirect());
        VmDom::ensureFetchableNode($self);
        $ctx = VmDomJitFrame::vmContext();
        $methodLc = strtolower($methodLc);

        return match ($methodLc) {
            'createelement' => VmDomJitDispatch::createElement($ctx, $self, $extra),
            'createattribute' => VmDomJitDispatch::createAttribute($ctx, $self, $extra),
            'createattributens' => VmDomJitDispatch::createAttributeNS($ctx, $self, $extra),
            'loadhtml' => VmDomJitDispatch::loadHTML($ctx, $self, $extra),
            'loadhtmlfile' => VmDomJitDispatch::loadHTMLFile($ctx, $self, $extra),
            'loadxml' => VmDomJitDispatch::loadXML($ctx, $self, $extra),
            'load' => VmDomJitDispatch::load($ctx, $self, $extra),
            'savexml' => VmDomJitDispatch::saveXML($self, $extra),
            'getelementbyid' => VmDomJitDispatch::getElementById($self, $extra),
            'appendchild' => VmDomJitDispatch::appendChild($ctx, $self, $extra),
            'append' => VmDomJitDispatch::append($ctx, $self, $extra),
            'prepend' => VmDomJitDispatch::prepend($ctx, $self, $extra),
            'after' => VmDomJitDispatch::after($ctx, $self, $extra),
            'before' => VmDomJitDispatch::before($ctx, $self, $extra),
            'replacewith' => VmDomJitDispatch::replaceWith($ctx, $self, $extra),
            'replacechildren' => VmDomJitDispatch::replaceChildren($ctx, $self, $extra),
            'createdocumentfragment' => VmDomJitDispatch::createDocumentFragment($ctx, $self, $extra),
            'createdocumenttype' => VmDomJitDispatch::createDocumentType($ctx, $self, $extra),
            'importnode' => VmDomJitDispatch::importNode($ctx, $self, $extra),
            'importlegacynode' => VmDomJitDispatch::importLegacyNode($ctx, $self, $extra),
            'adoptnode' => VmDomJitDispatch::adoptNode($ctx, $self, $extra),
            'getattribute' => VmDomJitDispatch::getAttribute($self, $extra),
            'getattributens' => VmDomJitDispatch::getAttributeNS($self, $extra),
            'hasattribute' => VmDomJitDispatch::hasAttribute($self, $extra),
            'hasattributes' => VmDomJitDispatch::hasAttributes($self, $extra),
            'getnodepath' => VmDomJitDispatch::getNodePath($self, $extra),
            'getelementsbytagname' => VmDomJitDispatch::dispatchGetElementsByTagName($ctx, $self, $extra),
            'count' => VmDomJitDispatch::dispatchCount($self, $extra),
            'hasattributens' => VmDomJitDispatch::hasAttributeNS($self, $extra),
            'getattributenode' => VmDomJitDispatch::getAttributeNode($ctx, $self, $extra),
            'setattribute' => VmDomJitDispatch::setAttribute($ctx, $self, $extra),
            'removeattribute' => VmDomJitDispatch::removeAttribute($ctx, $self, $extra),
            'getattributenodens' => VmDomJitDispatch::getAttributeNodeNS($ctx, $self, $extra),
            'setattributenodens' => VmDomJitDispatch::setAttributeNodeNS($ctx, $self, $extra),
            'setidattribute' => VmDomJitDispatch::setIdAttribute($self, $extra),
            'setidattributens' => VmDomJitDispatch::setIdAttributeNS($self, $extra),
            'setidattributenode' => VmDomJitDispatch::setIdAttributeNode($self, $extra),
            'isid' => VmDomJitDispatch::attrIsId($self, $extra),
            'add' => VmDomJitDispatch::tokenListAdd($ctx, $self, $extra),
            'remove' => VmDomJitDispatch::dispatchRemove($ctx, $self, $extra),
            'contains' => VmDomJitDispatch::dispatchContains($self, $extra),
            'item' => VmDomJitDispatch::dispatchItem($ctx, $self, $extra),
            'getnameditem' => VmDomJitDispatch::namedNodeMapGetNamedItem($self, $extra),
            'getnameditemns' => VmDomJitDispatch::namedNodeMapGetNamedItemNS($self, $extra),
            'getiterator' => VmDomJitDispatch::dispatchGetIterator($ctx, $self, $extra),
            'toggle' => VmDomJitDispatch::tokenListToggle($ctx, $self, $extra),
            'toggleattribute' => VmDomJitDispatch::toggleAttribute($ctx, $self, $extra),
            'getrootnode' => VmDomJitDispatch::getRootNode($self, $extra),
            'isequalnode' => VmDomJitDispatch::isEqualNode($self, $extra),
            'normalize' => VmDomJitDispatch::normalize($ctx, $self, $extra),
            'normalizedocument' => VmDomJitDispatch::normalizeDocument($ctx, $self, $extra),
            'createtextnode' => VmDomJitDispatch::createTextNode($ctx, $self, $extra),
            'splittext' => VmDomJitDispatch::splitText($ctx, $self, $extra),
            'query' => VmDomJitDispatch::xpathQuery($ctx, $self, $extra),
            'evaluate' => VmDomJitDispatch::xpathEvaluate($ctx, $self, $extra),
            'registernamespace' => VmDomJitDispatch::xpathRegisterNamespace($self, $extra),
            'registerphpfunctions' => VmDomJitDispatch::xpathRegisterPhpFunctions($self, $extra),
            'registerphpfunctionns' => VmDomJitDispatch::xpathRegisterPhpFunctionNS($ctx, $self, $extra),
            'comparedocumentposition' => VmDomJitDispatch::compareDocumentPosition($self, $extra),
            'c14n' => VmDomJitDispatch::c14n($ctx, $self, $extra),
            'queryselector' => VmDomJitDispatch::querySelector($self, $extra),
            'queryselectorall' => VmDomJitDispatch::querySelectorAll($ctx, $self, $extra),
            'closest' => VmDomJitDispatch::closest($self, $extra),
            'matches' => VmDomJitDispatch::matches($self, $extra),
            'savehtml' => VmDomJitDispatch::saveHtml($self, $extra),
            'rename' => VmDomJitDispatch::rename($ctx, $self, $extra),
            default => throw new \Error('Call to undefined method '.$self->class->name.'::'.$methodLc.'()'),
        };
    }
}
