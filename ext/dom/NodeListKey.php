<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNodeList::key() — Iterator protocol (php-src ext/dom/nodelist.c; #15397). */
final class NodeListKey extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NODE_LIST, 'DOMNodeList::key()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDom::nodeListKey($receiver));
    }
}
