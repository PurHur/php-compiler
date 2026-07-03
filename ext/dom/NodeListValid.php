<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNodeList::valid() — Iterator protocol (php-src ext/dom/nodelist.c; #15397). */
final class NodeListValid extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NODE_LIST, 'DOMNodeList::valid()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::nodeListValid($receiver));
    }
}
