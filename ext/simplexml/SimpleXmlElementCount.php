<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** SimpleXMLElement::count — sibling/child count (#3338). */
final class SimpleXmlElementCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SimpleXMLElement::count() called without $this');
        }
        // php-src simplexml.stub.php: count(): int (#30828).
        $this->requireExactUserArgCount($frame, 'SimpleXMLElement::count', 0);
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::count()'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmSimpleXml::countElements($entry));
        }
    }
}
