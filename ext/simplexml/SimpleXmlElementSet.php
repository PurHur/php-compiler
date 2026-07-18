<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/**
 * SimpleXMLElement::__set — element (or attribute-view) property write
 * (php-src ext/simplexml/simplexml.c sxe_prop_dim_write / sxe_property_write; #20539).
 */
final class SimpleXmlElementSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__set');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::__set() requires VM context');
        }
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('SimpleXMLElement::__set() called without property name and value');
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::__set()'
        );
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError('SimpleXMLElement::__set(): property name must be of type string');
        }
        VmSimpleXml::setChildProperty(
            $frame->vmContext,
            $entry,
            $nameVar->toString(),
            $frame->calledArgs[2],
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
