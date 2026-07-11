<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::append() — living-standard child mutation (php-src ext/dom/element.c; #14380). */
final class NodeAppend extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('append');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::append()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::append() requires VM context in this compiler build');
        }
        $args = \array_slice($frame->calledArgs, 1);
        VmDom::appendLiveStandardNodes($frame->vmContext, $receiver, $args);
    }
}
