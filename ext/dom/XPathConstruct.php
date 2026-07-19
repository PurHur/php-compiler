<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMXPath::__construct() — VM (#6066, php-src ext/dom/xpath.c). */
final class XPathConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_XPATH, 'DOMXPath::__construct()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMXPath::__construct() expects at least 1 argument');
        }
        $documentVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $documentVar->type) {
            throw new \TypeError(sprintf(
                'DOMXPath::__construct(): Argument #1 ($document) must be of type DOMDocument, %s given',
                VmDom::typeLabel($documentVar)
            ));
        }
        $document = $documentVar->toObject();
        if (!VmDom::isDocument($document)) {
            throw new \TypeError(sprintf(
                'DOMXPath::__construct(): Argument #1 ($document) must be of type DOMDocument, %s given',
                $document->class->name
            ));
        }
        // php-src: bool register_node_ns = true; zend_parse_parameters "O|b" (#20842).
        $registerNodeNS = true;
        if (isset($frame->calledArgs[2])) {
            $regVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $regVar->type) {
                throw new \TypeError(sprintf(
                    'DOMXPath::__construct(): Argument #2 ($registerNodeNS) must be of type bool, %s given',
                    VmDom::typeLabel($regVar)
                ));
            }
            $registerNodeNS = $regVar->toBool();
        }
        VmDom::ensureDocument($document);
        if (DomRegistry::has($receiver)) {
            $state = DomRegistry::state($receiver);
            $state->xpathDocumentId = $document->id;
            $state->xpathRegisterNodeNamespaces = $registerNodeNS;

            return;
        }
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_XPATH;
        $state->nodeName = 'DOMXPath';
        $state->xpathDocumentId = $document->id;
        $state->xpathRegisterNodeNamespaces = $registerNodeNS;
        DomRegistry::attach($receiver, $state);
    }
}
