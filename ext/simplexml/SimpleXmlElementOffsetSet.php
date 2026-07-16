<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * SimpleXMLElement::offsetSet — attribute write / element text (php-src sxe_prop_dim_write; #19536).
 */
final class SimpleXmlElementOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::offsetSet() requires VM context');
        }
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'SimpleXMLElement::offsetSet() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::offsetSet()'
        );
        VmSimpleXml::offsetSet(
            $frame->vmContext,
            $entry,
            $frame->calledArgs[1],
            $frame->calledArgs[2],
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
