<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMNode::isSameNode() — node identity check (php-src ext/dom/php_dom.c; #14379). */
final class NodeIsSameNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('isSameNode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNode::isSameNode', 1);
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::isSameNode()');
        $other = $this->otherNodeArg($frame->calledArgs[1], 'DOMNode::isSameNode()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::isSameNode($receiver, $other));
    }

    private function otherNodeArg(Variable $var, string $label, int $index): \PHPCompiler\VM\ObjectEntry
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
