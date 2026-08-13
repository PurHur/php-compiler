<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMNodeList::item() — VM (php-src ext/dom/nodelist.c; issue #14336).
 *
 * Exact user arity 1 — Zend ArgumentCountError (#30835; missed by #30616).
 */
final class NodeListItem extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('item');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNodeList::item', 1);
        $receiver = $this->receiver($frame, VmDom::CLASS_NODE_LIST, 'DOMNodeList::item()');
        $indexVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $indexVar->type && Variable::TYPE_FLOAT !== $indexVar->type) {
            throw new \TypeError(sprintf(
                'DOMNodeList::item(): Argument #1 ($index) must be of type int, %s given',
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
        $node = VmDom::nodeListItem($receiver, $index);
        if (null === $node) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($node);
    }
}
