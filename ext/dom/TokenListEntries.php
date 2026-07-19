<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** Dom\TokenList::entries() — VM (#20884). */
final class TokenListEntries extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('entries');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::entries()');
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMTokenList::entries() requires VM context');
        }
        $frame->returnVar->object(VmDomTokenList::entries($frame->vmContext, $receiver));
    }
}
