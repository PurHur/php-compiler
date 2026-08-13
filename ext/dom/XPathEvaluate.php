<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMXPath::evaluate() — VM (#6066, php-src ext/dom/xpath.c). */
final class XPathEvaluate extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('evaluate');
    }

    public function execute(Frame $frame): void
    {
        $this->requireUserArgCountRange($frame, 'DOMXPath::evaluate', 1, 3);
        $xpath = $this->xpathReceiver($frame, 'DOMXPath::evaluate()');
        // Pass $frame so caller strict_types rejects null like Zend (#30041).
        $expression = $this->stringArg(
            $frame->calledArgs[1],
            'DOMXPath::evaluate()',
            0,
            $frame,
            'expression'
        );
        $context = $this->optionalDomNodeArg($frame, 2, 'DOMXPath::evaluate()', 1);
        // php-src: bool register_node_ns = intern->register_node_ns; optional |b overrides (#20842).
        $registerNodeNS = DomRegistry::state($xpath)->xpathRegisterNodeNamespaces;
        if (isset($frame->calledArgs[3])) {
            $registerNodeNS = $this->optionalBoolArg($frame, 3, 'DOMXPath::evaluate()', 2);
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMXPath::evaluate() requires VM context in this compiler build');
        }
        $result = VmDomXPath::evaluate(
            $frame->vmContext,
            $xpath,
            $expression,
            $context,
            $registerNodeNS,
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    private function xpathReceiver(Frame $frame, string $label): \PHPCompiler\VM\ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf('%s must be called on an object, %s given', $label, VmDom::typeLabel($var)));
        }
        $object = $var->toObject();
        if (!VmDom::isXPath($object)) {
            throw new \TypeError(sprintf('%s must be called on a DOMXPath instance', $label));
        }

        return $object;
    }

    private function optionalDomNodeArg(Frame $frame, int $argIndex, string $label, int $paramIndex): ?\PHPCompiler\VM\ObjectEntry
    {
        if (!isset($frame->calledArgs[$argIndex])) {
            return null;
        }
        $var = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $paramIndex + 1,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $paramIndex + 1,
                $object->class->name
            ));
        }

        return $object;
    }

    private function optionalBoolArg(Frame $frame, int $argIndex, string $label, int $paramIndex): bool
    {
        if (!isset($frame->calledArgs[$argIndex])) {
            return false;
        }
        $var = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type bool, %s given',
                $label,
                $paramIndex + 1,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toBool();
    }
}
