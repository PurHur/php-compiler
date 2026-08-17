<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocumentFragment::appendXML() — VM (#15230, php-src ext/dom/documentfragment.c). */
final class FragmentAppendXML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('appendXML');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT_FRAGMENT, 'DOMDocumentFragment::appendXML()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocumentFragment::appendXML() expects exactly 1 argument');
        }
        $data = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocumentFragment::appendXML()',
            0,
            $frame,
            'data'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocumentFragment::appendXML() requires VM context in this compiler build');
        }
        $ok = VmDom::appendXML($frame->vmContext, $receiver, $data, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
