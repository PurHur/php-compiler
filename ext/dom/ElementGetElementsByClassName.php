<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * Dom\Element::getElementsByClassName() — VM PHP 8.5+ (php-src ext/dom/php_dom.stub.php; #20556, #27593).
 */
final class ElementGetElementsByClassName extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getElementsByClassName');
    }

    public function execute(Frame $frame): void
    {
        $element = $this->receiver($frame, VmDom::CLASS_ELEMENT, 'Dom\\Element::getElementsByClassName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Dom\\Element::getElementsByClassName() expects exactly 1 argument, 0 given');
        }
        $classNames = $this->stringArg($frame->calledArgs[1], 'Dom\\Element::getElementsByClassName()', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('Dom\\Element::getElementsByClassName() requires VM context in this compiler build');
        }
        $list = VmDom::getElementsByClassNameFromNode($frame->vmContext, $element, $classNames);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($list);
        }
    }
}
