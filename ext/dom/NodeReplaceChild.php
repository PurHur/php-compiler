<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMNode::replaceChild() — tree mutation (php-src ext/dom/node.c; #14394). */
final class NodeReplaceChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('replaceChild');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::replaceChild()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMNode::replaceChild() expects exactly 2 arguments');
        }
        $newChild = $this->nodeArg($frame->calledArgs[1], 'DOMNode::replaceChild()', 0);
        $oldChild = $this->nodeArg($frame->calledArgs[2], 'DOMNode::replaceChild()', 1);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::replaceChild() requires VM context in this compiler build');
        }
        $removed = VmDom::replaceChild($frame->vmContext, $receiver, $newChild, $oldChild);
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
