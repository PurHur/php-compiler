<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/**
 * SimpleXMLElement::offsetUnset — remove attribute / element (php-src sxe_prop_dim_delete; #19536).
 */
final class SimpleXmlElementOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('SimpleXMLElement::offsetUnset() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SimpleXMLElement::offsetUnset() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $entry = VmSimpleXml::requireElement(
            $frame->calledArgs[0]->resolveIndirect()->toObject(),
            'SimpleXMLElement::offsetUnset()'
        );
        VmSimpleXml::offsetUnset(
            $frame->vmContext,
            $entry,
            $frame->calledArgs[1],
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}
