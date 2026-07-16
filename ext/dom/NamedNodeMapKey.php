<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNamedNodeMap::key() — Iterator protocol (php-src ext/dom/namednodemap.c; #6189). */
final class NamedNodeMapKey extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::key()');
        if (null === $frame->returnVar) {
            return;
        }
        $key = VmDom::namedNodeMapKey($receiver);
        if (null === $key) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->string($key);
        }
    }
}
