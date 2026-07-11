<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::getElementsByTagNameNS() — VM (php-src ext/dom/php_dom.c; #14454). */
final class DocumentGetElementsByTagNameNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getElementsByTagNameNS');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::getElementsByTagNameNS()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMDocument::getElementsByTagNameNS() expects at least 2 arguments');
        }
        $namespace = $this->stringArg($frame->calledArgs[1], 'DOMDocument::getElementsByTagNameNS()', 0, $frame, 'namespace');
        $localName = $this->stringArg($frame->calledArgs[2], 'DOMDocument::getElementsByTagNameNS()', 1, $frame, 'localName');
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::getElementsByTagNameNS() requires VM context in this compiler build');
        }
        $list = VmDom::getElementsByTagNameNS($frame->vmContext, $receiver, $namespace, $localName);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($list);
        }
    }
}
