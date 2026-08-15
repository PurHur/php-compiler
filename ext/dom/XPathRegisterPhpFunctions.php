<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * DOMXPath::registerPhpFunctions() — enable php:function() callbacks (#19331).
 *
 * php-src: ext/dom/xpath.c — DOMXPath::registerPhpFunctions /
 * ext/dom/xpath_callbacks.c — php_dom_xpath_callbacks_update_method_handler
 *
 * At most 1 user arg — Zend ArgumentCountError (#31251; re-#31011).
 */
final class XPathRegisterPhpFunctions extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('registerPhpFunctions');
    }

    public function execute(Frame $frame): void
    {
        $this->requireAtMostUserArgCount($frame, 'DOMXPath::registerPhpFunctions', 1);
        $xpath = $this->xpathReceiver($frame, 'DOMXPath::registerPhpFunctions()');
        $restrict = null;
        if (isset($frame->calledArgs[1])) {
            $restrict = $frame->calledArgs[1]->resolveIndirect();
        }
        VmDomXPath::registerPhpFunctions($xpath, $restrict);
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
