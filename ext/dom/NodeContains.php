<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMNode::contains() — descendant check (php-src ext/dom/node.c; #14447). */
final class NodeContains extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('contains');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::contains()');
        if (!isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError('DOMNode::contains() expects exactly 1 argument, 0 given');
        }
        $other = $this->optionalDomNodeArg($frame->calledArgs[1], 'DOMNode::contains()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::contains($receiver, $other));
    }

    private function optionalDomNodeArg(Variable $var, string $label, int $index): ?\PHPCompiler\VM\ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }
}
