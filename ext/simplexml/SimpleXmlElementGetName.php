<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** SimpleXMLElement::getName — element local name (php-src ext/simplexml/sxe.c; #18038). */
final class SimpleXmlElementGetName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getName');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::getName() called without $this');
        }
        // php-src simplexml.stub.php: getName(): string (#30828).
        $this->requireExactUserArgCount($frame, 'SimpleXMLElement::getName', 0);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::getName()'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmSimpleXml::elementName($entry));
        }
    }
}
