<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMElement::__construct(string $qualifiedName, ?string $value = null, string $namespaceURI = "")
 * — orphaned element (php-src ext/dom/element.c; #22598).
 */
final class ElementConstruct extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'DOMElement::__construct()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'DOMElement::__construct() expects at least 1 argument, 0 given'
            );
        }
        $qualifiedName = $this->stringArg(
            $frame->calledArgs[1],
            'DOMElement::__construct()',
            0,
            $frame,
            'qualifiedName'
        );
        $value = null;
        if (isset($frame->calledArgs[2])) {
            $valueVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $valueVar->type) {
                $value = $this->stringArg(
                    $frame->calledArgs[2],
                    'DOMElement::__construct()',
                    1,
                    $frame,
                    'value'
                );
            }
        }
        $namespaceURI = '';
        if (isset($frame->calledArgs[3])) {
            $namespaceURI = $this->stringArg(
                $frame->calledArgs[3],
                'DOMElement::__construct()',
                2,
                $frame,
                'namespaceURI'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMElement::__construct() requires VM context in this compiler build');
        }
        VmDom::constructElement($frame->vmContext, $receiver, $qualifiedName, $value, $namespaceURI);
    }
}
