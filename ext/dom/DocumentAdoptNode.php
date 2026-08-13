<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMDocument::adoptNode() — cross-document reparent (php-src ext/dom/document.c; #19654, #24995).
 *
 * Real reparent is gated to PHP 8.3+ ({@see \PHPCompiler\CompilerVersion::supportsDomDocumentAdoptNode()});
 * reference / PROFILE&lt;8.3 throws Zend 8.2's {@code Error: Not yet implemented}.
 */
final class DocumentAdoptNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('adoptNode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMDocument::adoptNode', 1);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::adoptNode()');
        $nodeVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $nodeVar->type) {
            throw new \TypeError('DOMDocument::adoptNode(): Argument #1 ($node) must be of type DOMNode');
        }
        $node = $nodeVar->toObject();
        if (!VmDom::isDomNode($node)) {
            throw new \TypeError('DOMDocument::adoptNode(): Argument #1 ($node) must be of type DOMNode');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::adoptNode() requires VM context in this compiler build');
        }
        if (null === $frame->returnVar) {
            VmDom::adoptNode($frame->vmContext, $document, $node);

            return;
        }
        $frame->returnVar->copyFrom(VmDom::adoptNode($frame->vmContext, $document, $node));
    }
}
