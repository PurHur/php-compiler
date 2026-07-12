<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** SimpleXMLElement::offsetGet — child index or attribute (#3338). */
final class SimpleXmlElementOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::offsetGet() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('SimpleXMLElement::offsetGet() expects offset argument');
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::offsetGet()'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom(
                VmSimpleXml::offsetGet($frame->vmContext, $entry, $frame->calledArgs[1])
            );
        }
    }
}
