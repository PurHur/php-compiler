<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::isSupported() — feature probe (php-src ext/dom/node.c; #14456). */
final class NodeIsSupported extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('isSupported');
    }

    public function execute(Frame $frame): void
    {
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::isSupported()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMNode::isSupported() expects exactly 2 arguments');
        }
        $feature = $this->stringArg($frame->calledArgs[1], 'DOMNode::isSupported()', 0);
        $version = $this->stringArg($frame->calledArgs[2], 'DOMNode::isSupported()', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::hasFeature($feature, $version));
    }
}
