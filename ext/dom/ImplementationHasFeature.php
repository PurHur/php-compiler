<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMImplementation::hasFeature() — VM (#6140).
 *
 * Exact user arity 2 — Zend ArgumentCountError (#31090; missed by #31011).
 */
final class ImplementationHasFeature extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('hasFeature');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMImplementation::hasFeature', 2);
        self::receiver($frame, VmDom::CLASS_IMPLEMENTATION, 'DOMImplementation::hasFeature()');
        $feature = $this->stringArg(
            $frame->calledArgs[1],
            'DOMImplementation::hasFeature()',
            0,
            $frame,
            'feature'
        );
        $version = $this->stringArg(
            $frame->calledArgs[2],
            'DOMImplementation::hasFeature()',
            1,
            $frame,
            'version'
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(VmDom::hasFeature($feature, $version));
        }
    }
}
