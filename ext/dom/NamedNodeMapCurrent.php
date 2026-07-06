<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNamedNodeMap::current() — Iterator protocol (php-src ext/dom/namednodemap.c; #6189). */
final class NamedNodeMapCurrent extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::current()');
        if (null === $frame->returnVar) {
            return;
        }
        $node = VmDom::namedNodeMapCurrent($receiver);
        if (null === $node) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($node);
    }
}
