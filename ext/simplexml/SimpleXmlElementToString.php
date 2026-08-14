<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** SimpleXMLElement::__toString — text content (#3338). */
final class SimpleXmlElementToString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::__toString() called without $this');
        }
        // php-src simplexml.stub.php: __toString(): string (#30828).
        $this->requireExactUserArgCount($frame, 'SimpleXMLElement::__toString', 0);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::__toString()'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmSimpleXml::textContent($entry));
        }
    }
}
