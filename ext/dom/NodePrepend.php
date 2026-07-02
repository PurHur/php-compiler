<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::prepend() — living-standard child mutation (php-src ext/dom/element.c; #14380). */
final class NodePrepend extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('prepend');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::prepend()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::prepend() requires VM context in this compiler build');
        }
        $args = \array_slice($frame->calledArgs, 1);
        VmDom::prependLiveStandardNodes($frame->vmContext, $receiver, $args);
    }
}
