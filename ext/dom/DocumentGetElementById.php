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
        $this->requireExactUserArgCount($frame, 'DOMDocument::getElementById', 1);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::getElementById()');
        // Pass $frame so caller strict_types rejects null like Zend (#29942, re-#18215).
        $id = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocument::getElementById()',
            0,
            $frame,
            'elementId'
        );
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
