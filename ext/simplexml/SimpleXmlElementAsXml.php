<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** SimpleXMLElement::asXML — serialize node tree (php-src ext/simplexml/sxe.c; #18038). */
final class SimpleXmlElementAsXml extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('asXML');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::asXML() called without $this');
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::asXML()'
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
