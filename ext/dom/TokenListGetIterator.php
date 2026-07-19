<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * Dom\TokenList / DOMTokenList::getIterator() — VM (php-src ext/dom/token_list.c; #20884).
 */
final class TokenListGetIterator extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::getIterator()');
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMTokenList::getIterator() requires VM context');
        }
        $frame->returnVar->object(VmDomTokenList::getIterator($frame->vmContext, $receiver));
    }
}
