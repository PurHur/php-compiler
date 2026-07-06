<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNamedNodeMap::valid() — Iterator protocol (php-src ext/dom/namednodemap.c; #6189). */
final class NamedNodeMapValid extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::valid()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::namedNodeMapValid($receiver));
    }
}
