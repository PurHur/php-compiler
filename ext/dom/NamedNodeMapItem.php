<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMNamedNodeMap::item() — VM (php-src ext/dom/namednodemap.c; issue #6189).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#30835 follow-up).
 */
final class NamedNodeMapItem extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('item');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNamedNodeMap::item', 1);
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::item()');
        $indexVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $indexVar->type && Variable::TYPE_FLOAT !== $indexVar->type) {
            throw new \TypeError(sprintf(
                'DOMNamedNodeMap::item(): Argument #1 ($index) must be of type int, %s given',
                VmDom::typeLabel($indexVar)
            ));
        }
        $index = $indexVar->toInt();
        if (null === $frame->returnVar) {
            return;
        }
        if ($index < 0) {
            $frame->returnVar->null();

            return;
        }
        $node = VmDom::namedNodeMapItem($receiver, $index);
        if (null === $node) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($node);
    }
}
