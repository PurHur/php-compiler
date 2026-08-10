<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMElement::getElementsByTagName() — VM (#15298, php-src ext/dom/element.c). */
final class ElementGetElementsByTagName extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getElementsByTagName');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::getElementsByTagName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMElement::getElementsByTagName() expects at least 1 argument');
        }
        // Pass $frame so caller strict_types rejects null like Zend (#29959, re-#29942 / #18215).
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMElement::getElementsByTagName()',
            0,
            $frame,
            'qualifiedName'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::getElementsByTagName() requires VM context in this compiler build');
        }
        $list = VmDom::getElementsByTagNameFromNode($frame->vmContext, $element, $name);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($list);
        }
    }
}
