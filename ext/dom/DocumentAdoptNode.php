<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMDocument::adoptNode() — VM stub until cross-document adoption (#17494, php-src ext/dom/document.c). */
final class DocumentAdoptNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('adoptNode');
    }

    public function execute(Frame $frame): void
    {
        $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::adoptNode()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::adoptNode() expects at least 1 argument');
        }
        $nodeVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $nodeVar->type) {
            throw new \TypeError('DOMDocument::adoptNode(): Argument #1 ($node) must be of type DOMNode');
        }
        $node = $nodeVar->toObject();
        if (!VmDom::isDomNode($node)) {
            throw new \TypeError('DOMDocument::adoptNode(): Argument #1 ($node) must be of type DOMNode');
        }
        throw new \Error('Not yet implemented');
    }
}
