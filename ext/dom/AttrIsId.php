<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMAttr::isId() — VM (php-src ext/dom/attr.c; #20129).
 *
 * Returns true when this attribute is an ID (setIdAttribute* / HTML id / xml:id / DTD).
 */
final class AttrIsId extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('isId');
    }

    public function execute(Frame $frame): void
    {
        $attr = $this->receiver($frame, VmDom::CLASS_ATTR, 'DOMAttr::isId()');
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'DOMAttr::isId() expects exactly 0 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDom::attrIsId($attr));
    }
}
