<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNodeList::count() — Countable parity (php-src ext/dom/nodelist.c; issue #14517).
 *
 * Exact user arity 0 — Zend ArgumentCountError (#31011; missed by #30616).
 */
final class NodeListCount extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNodeList::count', 0);
        $receiver = $this->receiver($frame, VmDom::CLASS_NODE_LIST, 'DOMNodeList::count()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDom::nodeListCount($receiver));
    }
}
