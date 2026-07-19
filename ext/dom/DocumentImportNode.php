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
        $document = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::importNode()');
        $living = VmDomLiving::isLivingDocument($document);
        $label = $living ? 'Dom\\Document::importNode()' : 'DOMDocument::importNode()';
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(sprintf(
                '%s expects at least 1 argument, %d given',
                $label,
                \count($frame->calledArgs) - 1
            ));
        }
        $nodeVar = $frame->calledArgs[1]->resolveIndirect();
        $typeLabel = $living ? 'Dom\\Node' : 'DOMNode';
        $argName = $living ? 'node' : 'importedNode';
        if (Variable::TYPE_OBJECT !== $nodeVar->type) {
            throw new \TypeError(sprintf(
                '%s: Argument #1 ($%s) must be of type %s',
                $label,
                $argName,
                $typeLabel
            ));
        }
        $node = $nodeVar->toObject();
        if (null === $frame->vmContext) {
            throw new \LogicException($label.' requires VM context in this compiler build');
        }
        if ($living) {
            if (!VmDomLiving::isLivingNodeInstance($node, $frame->vmContext)) {
                throw new \TypeError(sprintf(
                    '%s: Argument #1 ($node) must be of type Dom\\Node',
                    $label
                ));
            }
        } elseif (!VmDom::isDomNode($node)) {
            throw new \TypeError(sprintf(
                '%s: Argument #1 ($importedNode) must be of type DOMNode',
                $label
            ));
        }
        $deep = isset($frame->calledArgs[2])
            ? VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[2],
                $living ? 'Dom\\Document::importNode' : 'DOMDocument::importNode',
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
