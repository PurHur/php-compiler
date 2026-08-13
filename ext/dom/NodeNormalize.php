<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMNode::normalize() — adjacent text merge (php-src ext/dom/node.c; #14395). */
final class NodeNormalize extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('normalize');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMNode::normalize', 0);
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMNode::normalize()');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMNode::normalize() requires VM context in this compiler build');
        }
        VmDom::normalizeLiveStandard($frame->vmContext, $receiver);
    }
}
