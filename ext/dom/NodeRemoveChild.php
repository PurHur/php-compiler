<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMNode::removeChild() — tree mutation (php-src ext/dom/node.c; #14394). */
final class NodeRemoveChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('removeChild');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::removeChild()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::removeChild() expects exactly 1 argument');
        }
        $child = $this->nodeArg($frame->calledArgs[1], 'DOMNode::removeChild()', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::removeChild() requires VM context in this compiler build');
        }
        $removed = VmDom::removeChild($frame->vmContext, $receiver, $child);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($removed);
        }
    }

    private function nodeArg(Variable $var, string $label, int $index): \PHPCompiler\VM\ObjectEntry
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
