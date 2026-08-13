<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMXPath::registerNamespace() — VM (#6066, php-src ext/dom/xpath.c). */
final class XPathRegisterNamespace extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('registerNamespace');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactUserArgCount($frame, 'DOMXPath::registerNamespace', 2);
        $xpath = $this->xpathReceiver($frame, 'DOMXPath::registerNamespace()');
        // Z_PARAM_STR: pass $frame so caller strict_types rejects null like Zend (#30301).
        $prefix = $this->stringArg(
            $frame->calledArgs[1],
            'DOMXPath::registerNamespace()',
            0,
            $frame,
            'prefix'
        );
        $namespaceUri = $this->stringArg(
            $frame->calledArgs[2],
            'DOMXPath::registerNamespace()',
            1,
            $frame,
            'namespace'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDomXPath::registerNamespace($xpath, $prefix, $namespaceUri));
    }

    private function xpathReceiver(Frame $frame, string $label): \PHPCompiler\VM\ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (\PHPCompiler\VM\Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(sprintf('%s must be called on an object, %s given', $label, VmDom::typeLabel($var)));
        }
        $object = $var->toObject();
        if (!VmDom::isXPath($object)) {
            throw new \TypeError(sprintf('%s must be called on a DOMXPath instance', $label));
        }

        return $object;
    }
}
