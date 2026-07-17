<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMXPath::registerPhpFunctionNS() — namespaced XPath PHP callbacks (#20119).
 *
 * php-src: ext/dom/xpath.c — PHP_METHOD(DOMXPath, registerPhpFunctionNS) /
 * ext/dom/xpath_callbacks.c — php_dom_xpath_callbacks_update_single_method_handler
 */
final class XPathRegisterPhpFunctionNS extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('registerPhpFunctionNS');
    }

    public function execute(Frame $frame): void
    {
        $xpath = $this->xpathReceiver($frame, 'DOMXPath::registerPhpFunctionNS()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(sprintf(
                'DOMXPath::registerPhpFunctionNS() expects exactly 3 arguments, %d given',
                max(0, \count($frame->calledArgs) - 1)
            ));
        }
        $namespaceUri = $this->pathStringArg(
            $frame->calledArgs[1],
            'DOMXPath::registerPhpFunctionNS()',
            0,
            'namespaceURI'
        );
        $name = $this->pathStringArg(
            $frame->calledArgs[2],
            'DOMXPath::registerPhpFunctionNS()',
            1,
            'name'
        );
        $callable = $frame->calledArgs[3]->resolveIndirect();
        $ctx = $frame->vmContext ?? throw new \LogicException('DOMXPath::registerPhpFunctionNS() requires VM context');
        VmDomXPath::registerPhpFunctionNS($ctx, $xpath, $namespaceUri, $name, $callable);
    }

    private function pathStringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(sprintf(
                '%s: Argument #%d ($%s) must be of type string, %s given',
                $label,
                $index + 1,
                $paramName,
                VmDom::typeLabel($var)
            ));
        }

        return $var->toString();
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
}
