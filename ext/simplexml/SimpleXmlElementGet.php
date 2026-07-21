<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/**
 * SimpleXMLElement::__get — child / attributes-view property access
 * (#3338, #21667; php-src sxe_prop_dim_read).
 */
final class SimpleXmlElementGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__get');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::__get() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('SimpleXMLElement::__get() called without property name');
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::__get()'
        );
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError('SimpleXMLElement::__get(): property name must be of type string');
        }
        $child = VmSimpleXml::childByName($frame->vmContext, $entry, $nameVar->toString());
        if (null !== $frame->returnVar) {
            if (null === $child) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->object($child);
            }
        }
    }
}
