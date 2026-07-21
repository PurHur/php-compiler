<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Variable;

/**
 * SimpleXMLElement::__isset — child / attributes-view presence
 * (php-src sxe.c has_property; #19707, #21667).
 */
final class SimpleXmlElementIsset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__isset');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::__isset() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('SimpleXMLElement::__isset() called without property name');
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::__isset()'
        );
        $nameVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $nameVar->type) {
            throw new \TypeError('SimpleXMLElement::__isset(): property name must be of type string');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                VmSimpleXml::childPropertyExists($entry, $nameVar->toString())
            );
        }
    }
}
