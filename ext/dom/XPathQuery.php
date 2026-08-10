<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMXPath::query() — VM (#6066, php-src ext/dom/xpath.c). */
final class XPathQuery extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('query');
    }

    public function execute(Frame $frame): void
    {
        $xpath = $this->xpathReceiver($frame, 'DOMXPath::query()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMXPath::query() expects at least 1 argument');
        }
        // Pass $frame so caller strict_types rejects null like Zend (#30041).
        $expression = $this->stringArg(
            $frame->calledArgs[1],
            'DOMXPath::query()',
            0,
            $frame,
            'expression'
        );
        $context = null;
        if (isset($frame->calledArgs[2])) {
            $context = $this->optionalDomNodeArg($frame->calledArgs[2], 'DOMXPath::query()', 1);
        }
        // php-src: bool register_node_ns = intern->register_node_ns; optional |b overrides (#20842).
        $registerNodeNS = DomRegistry::state($xpath)->xpathRegisterNodeNamespaces;
        if (isset($frame->calledArgs[3])) {
            $registerVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $registerVar->type) {
                throw new \TypeError(sprintf(
                    'DOMXPath::query(): Argument #3 ($registerNodeNS) must be of type bool, %s given',
                    VmDom::typeLabel($registerVar)
                ));
            }
            $registerNodeNS = $registerVar->toBool();
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMXPath::query() requires VM context in this compiler build');
        }
        $list = VmDomXPath::query($frame->vmContext, $xpath, $expression, $context, $registerNodeNS, 'query', $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($list);
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

    private function optionalDomNodeArg(Variable $var, string $label, int $index): ?\PHPCompiler\VM\ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                VmDom::typeLabel($var)
            ));
        }
        $object = $var->toObject();
        if (!VmDom::isDomNode($object)) {
            throw new \TypeError(sprintf(
                '%s expects argument #%d to be of type ?DOMNode, %s given',
                $label,
                $index + 1,
                $object->class->name
            ));
        }

        return $object;
    }
}
