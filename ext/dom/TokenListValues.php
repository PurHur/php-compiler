<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** Dom\TokenList::values() — VM (#20884). */
final class TokenListValues extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('values');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::values()');
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('DOMTokenList::values() requires VM context');
        }
        $frame->returnVar->object(VmDomTokenList::values($frame->vmContext, $receiver));
    }
}
