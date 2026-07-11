<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::after() — living-standard sibling mutation (php-src ext/dom/php_dom.c; #15345). */
final class NodeAfter extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('after');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::after()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::after() requires VM context in this compiler build');
        }
        $args = \array_slice($frame->calledArgs, 1);
        VmDom::afterLiveStandardNodes($frame->vmContext, $receiver, $args);
    }
}
