<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** Dom\TokenList::keys() — VM (#20884). */
final class TokenListKeys extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('keys');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::keys()');
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMTokenList::keys() requires VM context');
        }
        $frame->returnVar->object(VmDomTokenList::keys($frame->vmContext, $receiver));
    }
}
