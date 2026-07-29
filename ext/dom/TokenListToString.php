<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/**
 * Dom\TokenList / DOMTokenList::__toString — same serialization as $value
 * (php-src ext/dom/token_list.c; #24545).
 */
final class TokenListToString extends DomClassMethod
{
    public function __construct()
    {
        parent::__construct('__toString');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::__toString()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmDomTokenList::value($receiver));
    }
}
