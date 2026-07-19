<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Dom\Document::importLegacyNode() — legacy DOMNode → living Dom\* (php-src document.c; #20940).
 */
final class DocumentImportLegacyNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('importLegacyNode');
    }

    public function execute(Frame $frame): void
    {
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'Dom\\Document::importLegacyNode()');
        if (!VmDomLiving::isLivingDocument($document)) {
            throw new \Error(
                'Call to undefined method '.$document->class->name.'::importLegacyNode()'
            );
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'Dom\\Document::importLegacyNode() expects at least 1 argument, 0 given'
            );
        }
        $nodeVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $nodeVar->type) {
            throw new \TypeError(
                'Dom\\Document::importLegacyNode(): Argument #1 ($node) must be of type DOMNode'
            );
        }
        $node = $nodeVar->toObject();
        if (null === $frame->vmContext) {
            throw new \LogicException('Dom\\Document::importLegacyNode() requires VM context in this compiler build');
        }
        if (!VmDomLiving::isLegacyDomNodeInstance($node, $frame->vmContext)) {
            throw new \TypeError(
                'Dom\\Document::importLegacyNode(): Argument #1 ($node) must be of type DOMNode'
            );
        }
        $deep = isset($frame->calledArgs[2])
            ? VmMath::parseBoolBuiltinArg($frame->calledArgs[2], 'Dom\\Document::importLegacyNode', 2, 'deep')
            : false;
        if (null === $frame->returnVar) {
            VmDom::importLegacyNode($frame->vmContext, $document, $node, $deep);

            return;
        }
        $frame->returnVar->copyFrom(VmDom::importLegacyNode($frame->vmContext, $document, $node, $deep));
    }
}
