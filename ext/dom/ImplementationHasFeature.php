<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMImplementation::hasFeature() — VM (#6140). */
final class ImplementationHasFeature extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('hasFeature');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMImplementation::hasFeature() expects exactly 2 arguments');
        }
        self::receiver($frame, VmDom::CLASS_IMPLEMENTATION, 'DOMImplementation::hasFeature()');
        $feature = $this->stringArg($frame->calledArgs[1], 'DOMImplementation::hasFeature()', 0);
        $version = $this->stringArg($frame->calledArgs[2], 'DOMImplementation::hasFeature()', 1);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmDom::hasFeature($feature, $version));
        }
    }
}
