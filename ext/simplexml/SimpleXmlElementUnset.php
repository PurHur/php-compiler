<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/** SimpleXMLElement::__unset — remove child elements by name (php-src sxe.c sxe_prop_dim_delete; #19681). */
final class SimpleXmlElementUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unset');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::__unset() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('SimpleXMLElement::__unset() called without property name');
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::__unset()'
        );
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError('SimpleXMLElement::__unset(): property name must be of type string');
        }
        VmSimpleXml::unsetChildProperty($entry, $nameVar->toString());
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
