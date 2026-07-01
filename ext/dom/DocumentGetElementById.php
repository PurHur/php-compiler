<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::getElementById() — VM (php-src ext/dom/php_dom.c; #14378). */
final class DocumentGetElementById extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getElementById');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::getElementById()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::getElementById() expects exactly 1 argument');
        }
        $id = $this->stringArg($frame->calledArgs[1], 'DOMDocument::getElementById()', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $found = VmDom::getElementById($receiver, $id);
        if (null === $found) {
            $frame->returnVar->null();
        } else {
            $frame->returnVar->object($found);
        }
    }
}
