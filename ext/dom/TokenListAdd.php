<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMTokenList::add() — VM (php-src ext/dom/token_list.c; #16876). */
final class TokenListAdd extends DomClassMethod
{
  public function __construct()
  {
    parent::__construct('add');
  }

  public function execute(Frame $frame): void
  {
    $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::add()');
    VmDomTokenList::add($frame->vmContext, $receiver, $frame->calledArgs);
  }
}
