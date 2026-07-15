<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMNode::appendChild() — tree mutation (php-src ext/dom/node.c; #19178). */
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
        $child = $this->nodeArg($frame->calledArgs[1], 'DOMNode::appendChild()', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::appendChild() requires VM context in this compiler build');
        }
        $appended = VmDom::appendChild($frame->vmContext, $receiver, $child);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($appended);
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
        if (!VmDom::isAppendChildCandidate($object)) {
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
