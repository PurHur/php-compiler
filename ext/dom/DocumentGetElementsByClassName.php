<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * Dom\Document::getElementsByClassName() — VM PHP 8.5+ alias (php-src ext/dom/php_dom.stub.php; #20556, #27593).
 */
final class DocumentGetElementsByClassName extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getElementsByClassName');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'Dom\\Document::getElementsByClassName()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Dom\\Document::getElementsByClassName() expects exactly 1 argument, 0 given');
        }
        $classNames = $this->stringArg($frame->calledArgs[1], 'Dom\\Document::getElementsByClassName()', 0);
        if (null === $frame->vmContext) {
            throw new \LogicException('Dom\\Document::getElementsByClassName() requires VM context in this compiler build');
        }
        $list = VmDom::getElementsByClassName($frame->vmContext, $receiver, $classNames);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($list);
        }
    }
}
