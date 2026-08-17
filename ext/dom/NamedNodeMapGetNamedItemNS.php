<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNamedNodeMap::getNamedItemNS() — VM (php-src ext/dom/namednodemap.c; issue #17515). */
final class NamedNodeMapGetNamedItemNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getNamedItemNS');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_NAMED_NODE_MAP, 'DOMNamedNodeMap::getNamedItemNS()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMNamedNodeMap::getNamedItemNS() expects at least 2 arguments');
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMNamedNodeMap::getNamedItemNS()', 0);
        $localName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMNamedNodeMap::getNamedItemNS()',
            1,
            $frame,
            'localName'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $node = VmDom::namedNodeMapGetNamedItemNS($receiver, $namespace, $localName);
        if (null === $node) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($node);
    }
}
