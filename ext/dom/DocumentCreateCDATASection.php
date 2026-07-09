<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::createCDATASection() — VM (#17526, php-src ext/dom/php_dom.c). */
final class DocumentCreateCDATASection extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createCDATASection');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createCDATASection()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::createCDATASection() expects at least 1 argument');
        }
        $data = $this->stringArg($frame->calledArgs[1], 'DOMDocument::createCDATASection()', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createCDATASection() requires VM context in this compiler build');
        }
        $section = VmDom::createCdataSection($frame->vmContext, $data, $document);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($section);
        }
    }
}
