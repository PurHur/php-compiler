<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument::load() — VM (#15336, php-src ext/dom/php_dom.c).
 *
 * User arity 1–2 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class DocumentLoad extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('load');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'DOMDocument::load', 1, 2);
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::load()');
        $filename = $this->stringArg($frame->calledArgs[1], 'DOMDocument::load()', 0);
        $options = 0;
        if (isset($frame->calledArgs[2])) {
            // Z_PARAM_LONG $options (#25768).
            $options = $this->zParamLongArg($frame, 2, 'DOMDocument::load', 2, 'options');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::load() requires VM context in this compiler build');
        }
        $ok = VmDom::load($frame->vmContext, $receiver, $filename, $options, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
