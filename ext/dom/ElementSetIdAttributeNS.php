<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMElement::setIdAttributeNS() — VM (php-src ext/dom/element.c; #15300).
 *
 * Exact user arity 3 — Zend ArgumentCountError (#31032; missed by #31011).
 */
final class ElementSetIdAttributeNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setIdAttributeNS');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMElement::setIdAttributeNS', 3);
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setIdAttributeNS()');
        // php-src stub: string $namespace, string $qualifiedName — not ?string (#30091).
        $namespace = $this->stringArg(
            $frame->calledArgs[1],
            'DOMElement::setIdAttributeNS()',
            0,
            $frame,
            'namespace'
        );
        $localName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMElement::setIdAttributeNS()',
            1,
            $frame,
            'qualifiedName'
        );
        $isIdVar = $frame->calledArgs[3]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $isIdVar->type) {
            throw new \TypeError(
                'DOMElement::setIdAttributeNS(): Argument #3 ($isId) must be of type bool, '
                .VmDom::typeLabel($isIdVar).' given'
            );
        }
        VmDom::setIdAttributeNS($element, $namespace, $localName, $isIdVar->toBool());
    }
}
