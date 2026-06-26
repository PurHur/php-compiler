<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::loadXML() — VM (#11895, php-src ext/dom/document.c). */
final class DocumentLoadXML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('loadXML');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::loadXML()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::loadXML() expects at least 1 argument');
        }
        $xml = $this->stringArg($frame->calledArgs[1], 'DOMDocument::loadXML()', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::loadXML() requires VM context in this compiler build');
        }
        $ok = VmDom::loadXML($frame->vmContext, $receiver, $xml);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
