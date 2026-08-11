<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::getAttributeNS() — VM (#14313, php-src ext/dom/php_dom.c). */
final class ElementGetAttributeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getAttributeNS()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMElement::getAttributeNS() expects at least 2 arguments');
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMElement::getAttributeNS()', 0);
        // Pass $frame so caller strict_types rejects null localName (#30091, peer #29985).
        $localName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMElement::getAttributeNS()',
            1,
            $frame,
            'localName'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $value = VmDom::getAttributeNS($element, $namespace, $localName);
        if (null === $value) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($value);
        }
    }
}
