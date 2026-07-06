<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNodeList::count() — Countable parity (php-src ext/dom/nodelist.c; issue #14517). */
final class NodeListCount extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NODE_LIST, 'DOMNodeList::count()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDom::nodeListCount($receiver));
    }
}
