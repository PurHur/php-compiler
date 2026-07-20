<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNamedNodeMap / Dom\NamedNodeMap::getIterator()
 * (php-src ext/dom/php_dom.stub.php; #21298).
 */
final class NamedNodeMapGetIterator extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::getIterator()');
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNamedNodeMap::getIterator() requires VM context');
        }
        $frame->returnVar->object(VmDom::namedNodeMapGetIterator($frame->vmContext, $receiver));
    }
}
