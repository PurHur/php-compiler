<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMDocument::importNode() — cross-document node import (php-src ext/dom/php_dom.c; #14337). */
final class DocumentImportNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('importNode');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::importNode()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::importNode() expects at least 1 argument');
        }
        $nodeVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $nodeVar->type) {
            throw new \TypeError('DOMDocument::importNode(): Argument #1 ($importedNode) must be of type DOMNode');
        }
        $node = $nodeVar->toObject();
        if (!VmDom::isDomNode($node)) {
            throw new \TypeError('DOMDocument::importNode(): Argument #1 ($importedNode) must be of type DOMNode');
        }
        $deep = isset($frame->calledArgs[2])
            ? VmMath::parseBoolBuiltinArg($frame->calledArgs[2], 'DOMDocument::importNode', 2, 'deep')
            : false;
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::importNode() requires VM context in this compiler build');
        }
        if (null === $frame->returnVar) {
            VmDom::importNode($frame->vmContext, $document, $node, $deep);

            return;
        }
        $frame->returnVar->copyFrom(VmDom::importNode($frame->vmContext, $document, $node, $deep));
    }
}
