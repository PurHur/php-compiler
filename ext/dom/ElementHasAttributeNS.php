<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::hasAttributeNS() — VM (#14313, php-src ext/dom/php_dom.c). */
final class ElementHasAttributeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('hasAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::hasAttributeNS()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMElement::hasAttributeNS() expects at least 2 arguments');
        }
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
