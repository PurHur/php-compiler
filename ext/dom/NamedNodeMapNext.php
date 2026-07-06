<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNamedNodeMap::next() — Iterator protocol (php-src ext/dom/namednodemap.c; #6189). */
final class NamedNodeMapNext extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::next()');
        VmDom::namedNodeMapNext($receiver);
    }
}
