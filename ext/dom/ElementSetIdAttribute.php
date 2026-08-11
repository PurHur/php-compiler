<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMElement::setIdAttribute() — VM (php-src ext/dom/node.c; #14493). */
final class ElementSetIdAttribute extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('setIdAttribute');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::setIdAttribute()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMElement::setIdAttribute() expects at least 2 arguments');
        }
        // Pass $frame so caller strict_types rejects null like Zend (#30091, peer #29985).
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMElement::setIdAttribute()',
            0,
            $frame,
            'qualifiedName'
        );
        $isIdVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $isIdVar->type) {
            throw new \TypeError(
                'DOMElement::setIdAttribute(): Argument #2 ($isId) must be of type bool, '
                .VmDom::typeLabel($isIdVar).' given'
            );
        }
        VmDom::setIdAttribute($element, $name, $isIdVar->toBool());
    }
}
