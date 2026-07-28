<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNode::isEqualNode() — structural node equality (php-src ext/dom/node.c; #15195, #24462).
 *
 * Stub is {@code isEqualNode(?DOMNode $otherNode): bool}; null other → false (not TypeError).
 */
final class NodeIsEqualNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('isEqualNode');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::isEqualNode()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNode::isEqualNode() expects exactly 1 argument');
        }
        $other = $this->otherNodeArg($frame->calledArgs[1], 'DOMNode::isEqualNode()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $other) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmDom::isEqualNode($receiver, $other));
    }

    private function otherNodeArg(\PHPCompiler\VM\Variable $var, string $label, int $index): ?\PHPCompiler\VM\ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
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
