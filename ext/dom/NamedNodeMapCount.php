<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNamedNodeMap::count() — Countable parity (php-src ext/dom/namednodemap.c; issue #6189). */
final class NamedNodeMapCount extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::count()');
        if (null === $frame->returnVar) {
            return;
        }
        $length = $receiver->getProperty(VmDom::PROP_LENGTH)->resolveIndirect()->toInt();
        $frame->returnVar->int($length);
    }
}
