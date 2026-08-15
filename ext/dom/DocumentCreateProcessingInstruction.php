<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument::createProcessingInstruction() — VM (#6318, php-src ext/dom/php_dom.c).
 *
 * User arity 1–2 — Zend ArgumentCountError (#31251; re-#31011).
 */
final class DocumentCreateProcessingInstruction extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createProcessingInstruction');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'DOMDocument::createProcessingInstruction', 1, 2);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createProcessingInstruction()');
        $target = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocument::createProcessingInstruction()',
            0,
            $frame,
            'target'
        );
        $data = '';
        if (\count($frame->calledArgs) >= 3) {
            $data = $this->stringArg(
                $frame->calledArgs[2],
                'DOMDocument::createProcessingInstruction()',
                1,
                $frame,
                'data'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createProcessingInstruction() requires VM context in this compiler build');
        }
        $pi = VmDom::createProcessingInstruction($frame->vmContext, $target, $data, $document);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($pi);
        }
    }
}
