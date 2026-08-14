<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMElement::getAttributeNS() — VM (#14313, php-src ext/dom/php_dom.c).
 *
 * Exact user arity 2 — Zend ArgumentCountError (#31011; missed by #30616).
 */
final class ElementGetAttributeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::getAttributeNS', 2);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getAttributeNS()');
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
