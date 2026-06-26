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
        $child = $this->elementArg($frame->calledArgs[1], 'DOMElement::appendChild()', 0);
        $appended = VmDom::appendChild($receiver, $child);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($appended);
        }
    }

    private function elementArg(Variable $var, string $label, int $index): \PHPCompiler\VM\ObjectEntry
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
        if (!VmDom::isElement($object)) {
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
