<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** SimpleXMLElement::offsetExists — child index or attribute (#3338). */
final class SimpleXmlElementOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('SimpleXMLElement::offsetExists() expects offset argument');
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::offsetExists()'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(
                VmSimpleXml::offsetExists($entry, $frame->calledArgs[1])
            );
        }
    }
}
