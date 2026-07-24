<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMElement::appendChild() — VM (#11895, php-src ext/dom/node.c). */
final class ElementAppendChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('appendChild');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::appendChild()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMElement::appendChild() expects exactly 1 argument');
        }
        $child = $this->nodeChildArg($frame->calledArgs[1], 'DOMElement::appendChild()', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::appendChild() requires VM context in this compiler build');
        }
        $appended = VmDom::appendChild($frame->vmContext, $receiver, $child);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($appended);
        }
    }

    private function nodeChildArg(Variable $var, string $label, int $index): \PHPCompiler\VM\ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type DOMNode, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        // php-src stub: DOMNode $node — DOMDocument is a DOMNode subclass; hierarchy
        // rejects documents later with DOMException (not TypeError) (#22698).
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type DOMNode, %s given',
                $label,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }
}
