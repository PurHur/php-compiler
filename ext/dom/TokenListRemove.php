<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMTokenList::remove() — VM (php-src ext/dom/token_list.c; #16876). */
final class TokenListRemove extends DomClassMethod
{
  public function __construct()
  {
    parent::__construct('remove');
  }

  public function execute(Frame $frame): void
  {
    $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::remove()');
    VmDomTokenList::remove($frame->vmContext, $receiver, $frame->calledArgs);
  }
}
