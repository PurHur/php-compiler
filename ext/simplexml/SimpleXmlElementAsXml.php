<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * SimpleXMLElement::asXML / saveXML — serialize node tree
 * (php-src ext/simplexml/sxe.c zim_SimpleXMLElement_asXML + saveXML FALIAS; #18038, #19413).
 */
final class SimpleXmlElementAsXml extends VmClassMethod
{
    public function __construct(string $name = 'asXML')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $label = 'SimpleXMLElement::'.$this->name.'()';
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            $label
        );
        if (null === $frame->returnVar) {
            return;
        }
        $includeDeclaration = SimpleXmlRegistry::documentKey($entry) === $entry->id
            && !SimpleXmlRegistry::isView($entry)
            && !SimpleXmlRegistry::isAttributesView($entry);
        $xml = VmSimpleXml::asXml($entry, $includeDeclaration);
        if (false === $xml) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($xml);
        }
    }
}
