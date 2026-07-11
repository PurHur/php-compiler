<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMText::splitText() — VM (#17513, php-src ext/dom/text.c). */
final class TextSplitText extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('splitText');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->domRegistryNodeReceiver($frame, 'DOMText::splitText()');
        if (!VmDom::isTextOrCdataNode($receiver)) {
            throw new \TypeError('DOMText::splitText() must be called on a text node');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('DOMText::splitText() expects at least 1 argument');
        }
        $offsetVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $offsetVar->type && Variable::TYPE_FLOAT !== $offsetVar->type) {
            throw new \TypeError(sprintf(
                'DOMText::splitText(): Argument #1 ($offset) must be of type int, %s given',
                VmDom::typeLabel($offsetVar)
            ));
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMText::splitText() requires VM context in this compiler build');
        }
        $tail = VmDom::textSplitText($frame->vmContext, $receiver, $offsetVar->toInt());
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $tail) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($tail);
    }
}
