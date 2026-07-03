<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::replaceWith() — living-standard sibling mutation (php-src ext/dom/php_dom.c; #15345). */
final class NodeReplaceWith extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('replaceWith');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::replaceWith()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::replaceWith() requires VM context in this compiler build');
        }
        $args = \array_slice($frame->calledArgs, 1);
        VmDom::replaceWithLiveStandardNodes($frame->vmContext, $receiver, $args);
    }
}
