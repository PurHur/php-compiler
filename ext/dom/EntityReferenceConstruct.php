<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMEntityReference::__construct(string $name)
 * — orphaned entity reference (php-src ext/dom/entityreference.c; #24631).
 */
final class EntityReferenceConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver(
            $frame,
            VmDom::CLASS_ENTITY_REFERENCE,
            'DOMEntityReference::__construct()'
        );
        $argc = \count($frame->calledArgs) - 1;
        if ($argc !== 1) {
            throw new \ArgumentCountError(
                'DOMEntityReference::__construct() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $name = $this->stringArg(
            $frame->calledArgs[1],
            'DOMEntityReference::__construct()',
            0,
            $frame,
            'name'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException(
                'DOMEntityReference::__construct() requires VM context in this compiler build'
            );
        }
        VmDom::constructEntityReference($frame->vmContext, $receiver, $name);
    }
}
