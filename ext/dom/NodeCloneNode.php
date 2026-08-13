<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;

/** DOMNode::cloneNode() — deep/shallow subtree clone (php-src ext/dom/node.c; #14381). */
final class NodeCloneNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('cloneNode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtMostUserArgCount($frame, 'DOMNode::cloneNode', 1);
        $receiver = $this->cloneableNodeReceiver($frame, 'DOMNode::cloneNode()');
        $deep = isset($frame->calledArgs[1])
            ? VmMath::parseBoolBuiltinArg($frame->calledArgs[1], 'DOMNode::cloneNode', 1, 'deep')
            : false;
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::cloneNode() requires VM context in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmDom::cloneNode($frame->vmContext, $receiver, $deep));
    }

    private function cloneableNodeReceiver(Frame $frame, string $label): \PHPCompiler\VM\ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s must be called on an object, %s given',
                $label,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (!VmDom::isCloneableNode($object)) {
            throw new \TypeError(sprintf(
                '%s must be called on a DOMNode instance',
                $label
            ));
        }

        return $object;
    }
}
