<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMDocument::importNode() — cross-document node import (php-src ext/dom/php_dom.c; #14337, #20940). */
final class DocumentImportNode extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('importNode');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'DOMDocument::importNode', 1, 2);
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::importNode()');
        $living = VmDomLiving::isLivingDocument($document);
        $label = $living ? 'Dom\\Document::importNode' : 'DOMDocument::importNode';
        $nodeVar = $frame->calledArgs[1]->resolveIndirect();
        $typeLabel = $living ? 'Dom\\Node' : 'DOMNode';
        // php-src stub: importNode(DOMNode|Dom\Node $node, …) — always $node (#30410).
        if (Variable::TYPE_OBJECT !== $nodeVar->type) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($node) must be of type %s, %s given',
                $label,
                $typeLabel,
                VmDom::typeLabel($nodeVar)
            ));
        }
        $node = $nodeVar->toObject();
        if (null === $frame->vmContext) {
            throw new \LogicException($label.'() requires VM context in this compiler build');
        }
        if ($living) {
            if (!VmDomLiving::isLivingNodeInstance($node, $frame->vmContext)) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #1 ($node) must be of type Dom\\Node, %s given',
                    $label,
                    $node->class->name
                ));
            }
        } elseif (!VmDom::isDomNode($node)) {
            throw new \TypeError(sprintf(
                '%s(): Argument #1 ($node) must be of type DOMNode, %s given',
                $label,
                $node->class->name
            ));
        }
        $deep = isset($frame->calledArgs[2])
            ? VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[2],
                $label,
                2,
                'deep'
            )
            : false;
        if (null === $frame->returnVar) {
            VmDom::importNode($frame->vmContext, $document, $node, $deep);

            return;
        }
        $frame->returnVar->copyFrom(VmDom::importNode($frame->vmContext, $document, $node, $deep));
    }
}
