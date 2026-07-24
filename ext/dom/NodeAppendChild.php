<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMNode::appendChild() — VM (#19178, php-src ext/dom/node.c). */
final class NodeAppendChild extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('appendChild');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::appendChild()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::appendChild() expects exactly 1 argument');
        }
        $child = $this->nodeChildArg($frame->calledArgs[1], 'DOMNode::appendChild()', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::appendChild() requires VM context in this compiler build');
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
            throw new \TypeError(\sprintf(
                '%s expects argument #%d to be of type DOMNode, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        // php-src stub: DOMNode $node — accept Document/etc., hierarchy in VmDom (#22698).
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(\sprintf(
                '%s expects argument #%d to be of type DOMNode, %s given',
                $label,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }
}
