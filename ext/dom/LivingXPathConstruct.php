<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * Dom\XPath::__construct() — living Document only (php-src ext/dom/php_dom.stub.php; #20757).
 */
final class LivingXPathConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDomLiving::CLASS_XPATH, 'Dom\\XPath::__construct()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('Dom\\XPath::__construct() expects at least 1 argument');
        }
        $documentVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $documentVar->type) {
            throw new \TypeError(sprintf(
                'Dom\\XPath::__construct(): Argument #1 ($document) must be of type Dom\\Document, %s given',
                VmDom::typeLabel($documentVar)
            ));
        }
        $document = $documentVar->toObject();
        if (!VmDomLiving::isLivingDocument($document) && VmDomLiving::CLASS_DOCUMENT !== strtolower($document->class->name)) {
            throw new \TypeError(sprintf(
                'Dom\\XPath::__construct(): Argument #1 ($document) must be of type Dom\\Document, %s given',
                $document->class->name
            ));
        }
        if (!VmDom::isDocument($document)) {
            throw new \TypeError(sprintf(
                'Dom\\XPath::__construct(): Argument #1 ($document) must be of type Dom\\Document, %s given',
                $document->class->name
            ));
        }
        VmDom::ensureDocument($document);
        if (DomRegistry::has($receiver)) {
            $state = DomRegistry::state($receiver);
            $state->xpathDocumentId = $document->id;

            return;
        }
        $state = new DomNodeState();
        $state->nodeType = DomConstants::XML_XPATH;
        $state->nodeName = 'Dom\\XPath';
        $state->xpathDocumentId = $document->id;
        DomRegistry::attach($receiver, $state);
    }
}
