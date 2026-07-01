<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMImplementation::getFeature() — VM (#14494, php-src ext/dom/implementation.c). */
final class ImplementationGetFeature extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getFeature');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMImplementation::getFeature() expects exactly 2 arguments');
        }
        self::receiver($frame, VmDom::CLASS_IMPLEMENTATION, 'DOMImplementation::getFeature()');
        $this->stringArg($frame->calledArgs[1], 'DOMImplementation::getFeature()', 0);
        $this->stringArg($frame->calledArgs[2], 'DOMImplementation::getFeature()', 1);
        throw new \Error('Not yet implemented');
    }
}
