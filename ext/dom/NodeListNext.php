<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNodeList::next() — Iterator protocol (php-src ext/dom/nodelist.c; #15397). */
final class NodeListNext extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NODE_LIST, 'DOMNodeList::next()');
        VmDom::nodeListNext($receiver);
    }
}
