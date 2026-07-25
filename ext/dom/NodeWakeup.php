<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMNode::__wakeup() — refuse unserialization unless a subclass overrides (#23073).
 *
 * php-src: ext/dom/node.c — PHP_METHOD(DOMNode, __wakeup) (GH-8996 / 24e5e4ec0d).
 */
final class NodeWakeup extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__wakeup');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('DOMNode::__wakeup() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect()->toObject();
        throw new \Exception(
            "Unserialization of '".$receiver->class->name."' is not allowed, unless unserialization methods are implemented in a subclass"
        );
    }
}
