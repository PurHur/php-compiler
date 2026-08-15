<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNode::isSupported() — feature probe (php-src ext/dom/node.c; #14456).
 *
 * Exact user arity 2 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class NodeIsSupported extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('isSupported');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNode::isSupported', 2);
        $node = $this->domRegistryNodeReceiver($frame, 'DOMNode::isSupported()');
        $feature = $this->stringArg($frame->calledArgs[1], 'DOMNode::isSupported()', 0);
        $version = $this->stringArg($frame->calledArgs[2], 'DOMNode::isSupported()', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::hasFeature($feature, $version));
    }
}
