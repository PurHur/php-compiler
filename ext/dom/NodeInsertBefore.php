<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMNode::insertBefore() — tree mutation (php-src ext/dom/node.c; #14394). */
final class NodeInsertBefore extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('insertBefore');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::insertBefore()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::insertBefore() expects at least 1 argument');
        }
        $newChild = $this->nodeArg($frame->calledArgs[1], 'DOMNode::insertBefore()', 0);
        $refChild = null;
        if (isset($frame->calledArgs[2])) {
            $refVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $refVar->type) {
                $refChild = $this->nodeArg($frame->calledArgs[2], 'DOMNode::insertBefore()', 1);
            }
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::insertBefore() requires VM context in this compiler build');
        }
        $inserted = VmDom::insertBefore($frame->vmContext, $receiver, $newChild, $refChild);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($inserted);
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
