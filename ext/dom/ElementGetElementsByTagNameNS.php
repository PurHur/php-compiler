<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMElement::getElementsByTagNameNS() — VM (php-src ext/dom/element.c; #14454).
 *
 * Exact user arity 2 — Zend ArgumentCountError (#31032; missed by #31011).
 */
final class ElementGetElementsByTagNameNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getElementsByTagNameNS');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::getElementsByTagNameNS', 2);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getElementsByTagNameNS()');
        // php-src stub: ?string $namespace — null must not TypeError under strict (#30091).
        $namespace = $this->nullableStringArg(
            $frame->calledArgs[1],
            'DOMElement::getElementsByTagNameNS()',
            0
        );
        $localName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMElement::getElementsByTagNameNS()',
            1,
            $frame,
            'localName'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::getElementsByTagNameNS() requires VM context in this compiler build');
        }
        // Zend treats null namespace like "" for matching (no-namespace elements).
        $list = VmDom::getElementsByTagNameNSFromNode(
            $frame->vmContext,
            $element,
            $namespace ?? '',
            $localName
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($list);
        }
    }
}
