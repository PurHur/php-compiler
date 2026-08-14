<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMElement::hasAttributeNS() — VM (#14313, php-src ext/dom/php_dom.c).
 *
 * Exact user arity 2 — Zend ArgumentCountError (#31032; missed by #31011).
 */
final class ElementHasAttributeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('hasAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::hasAttributeNS', 2);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::hasAttributeNS()');
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMElement::hasAttributeNS()', 0);
        // Pass $frame so caller strict_types rejects null localName (#30091, peer #29985).
        $localName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMElement::hasAttributeNS()',
            1,
            $frame,
            'localName'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::hasAttributeNS($element, $namespace, $localName));
    }
}
