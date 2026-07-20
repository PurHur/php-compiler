<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNodeList / Dom\NodeList / Dom\HTMLCollection::getIterator()
 * (php-src ext/dom/php_dom.stub.php; #21298).
 */
final class NodeListGetIterator extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NODE_LIST, 'DOMNodeList::getIterator()');
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNodeList::getIterator() requires VM context');
        }
        $frame->returnVar->object(VmDom::nodeListGetIterator($frame->vmContext, $receiver));
    }
}
