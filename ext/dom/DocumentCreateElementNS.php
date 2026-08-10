<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMDocument::createElementNS() — VM (#14314, php-src ext/dom/php_dom.c). */
final class DocumentCreateElementNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('createElementNS');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::createElementNS()');
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('DOMDocument::createElementNS() expects at least 2 arguments');
        }
        $namespace = $this->nullableStringArg($frame->calledArgs[1], 'DOMDocument::createElementNS()', 0);
        // Pass $frame so caller strict_types rejects null like Zend (#29985, re-#29942).
        $qualifiedName = $this->stringArg(
            $frame->calledArgs[2],
            'DOMDocument::createElementNS()',
            1,
            $frame,
            'qualifiedName'
        );
        $value = '';
        if (isset($frame->calledArgs[3])) {
            $value = $this->stringArg(
                $frame->calledArgs[3],
                'DOMDocument::createElementNS()',
                2,
                $frame,
                'value'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::createElementNS() requires VM context in this compiler build');
        }
        $element = VmDom::createElementNS($frame->vmContext, $namespace, $qualifiedName, $document, $value);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($element);
        }
    }
}
