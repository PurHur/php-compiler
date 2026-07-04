<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNamedNodeMap::getNamedItem() — VM (php-src ext/dom/namednodemap.c; issue #6189). */
final class NamedNodeMapGetNamedItem extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getNamedItem');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::getNamedItem()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMNamedNodeMap::getNamedItem() expects at least 1 argument');
        }
        $name = $this->stringArg($frame->calledArgs[1], 'DOMNamedNodeMap::getNamedItem()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $node = VmDom::namedNodeMapGetNamedItem($receiver, $name);
        if (null === $node) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($node);
    }
}
