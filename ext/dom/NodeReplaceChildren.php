<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::replaceChildren() — living-standard child replacement (php-src ext/dom/parentnode.c; #16822). */
final class NodeReplaceChildren extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('replaceChildren');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::replaceChildren()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::replaceChildren() requires VM context in this compiler build');
        }
        $args = \array_slice($frame->calledArgs, 1);
        VmDom::replaceChildrenLiveStandardNodes($frame->vmContext, $receiver, $args);
    }
}
