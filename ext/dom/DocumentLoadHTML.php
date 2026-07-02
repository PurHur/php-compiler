<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMDocument::loadHTML() — VM (#14356, php-src ext/dom/php_dom.c). */
final class DocumentLoadHTML extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('loadHTML');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_DOCUMENT, 'DOMDocument::loadHTML()');
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMDocument::loadHTML() expects at least 1 argument');
        }
        $html = $this->stringArg($frame->calledArgs[1], 'DOMDocument::loadHTML()', 0);
        $options = 0;
        if (isset($frame->calledArgs[2])) {
            $optionsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $optionsVar->type) {
                throw new \TypeError('DOMDocument::loadHTML(): Argument #2 ($options) must be of type int');
            }
            $options = $optionsVar->toInt();
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMDocument::loadHTML() requires VM context in this compiler build');
        }
        $ok = VmDom::loadHTML($frame->vmContext, $receiver, $html, $options);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
