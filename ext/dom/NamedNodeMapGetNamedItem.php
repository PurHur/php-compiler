<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNamedNodeMap::getNamedItem() — VM (php-src ext/dom/namednodemap.c; issue #6189).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#30835 follow-up).
 */
final class NamedNodeMapGetNamedItem extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getNamedItem');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNamedNodeMap::getNamedItem', 1);
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::getNamedItem()');
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMNamedNodeMap::getNamedItem()',
            0,
            $frame,
            'qualifiedName'
        );
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
