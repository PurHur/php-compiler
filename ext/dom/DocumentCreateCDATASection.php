<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * DOMDocument / Dom\Document::createCDATASection() — VM (#17526, #21064).
 *
 * php-src: ext/dom/document.c PHP_METHOD(DOMDocument, createCDATASection)
 * (follow_spec + HTML document → NOT_SUPPORTED_ERR).
 */
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
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $data = $this->stringArg(
            $frame->calledArgs[1],
            'DOMDocument::createCDATASection()',
            0,
            $frame,
            'data'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createCDATASection() requires VM context in this compiler build');
        }
        // Living Dom\* only (php_dom_follow_spec_intern): HTML docs cannot create CDATA (#21064).
        if (VmDomLiving::isLivingDocument($document) && DomRegistry::state($document)->isHtmlDocument) {
            throw new \DOMException(
                'This operation is not supported for HTML documents',
                DomExceptionConstants::NOT_SUPPORTED_ERR
            );
        }
        $section = VmDom::createCdataSection($frame->vmContext, $data, $document);
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($section);
        }
    }
}
