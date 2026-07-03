<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::before() — living-standard sibling mutation (php-src ext/dom/php_dom.c; #15345). */
final class NodeBefore extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('before');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::before()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::before() requires VM context in this compiler build');
        }
        $args = \array_slice($frame->calledArgs, 1);
        VmDom::beforeLiveStandardNodes($frame->vmContext, $receiver, $args);
    }
}
