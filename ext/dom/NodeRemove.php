<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::remove() — living-standard tree mutation (php-src ext/dom/php_dom.c; #15345). */
final class NodeRemove extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('remove');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::remove()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::remove() requires VM context in this compiler build');
        }
        VmDom::removeLiveStandard($frame->vmContext, $receiver);
    }
}
