<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNodeList::current() — Iterator protocol (php-src ext/dom/nodelist.c; #15397). */
final class NodeListCurrent extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NODE_LIST, 'DOMNodeList::current()');
        if (null === $frame->returnVar) {
            return;
        }
        $node = VmDom::nodeListCurrent($receiver);
        if (null === $node) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($node);
    }
}
