<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMTokenList::count() — VM (php-src ext/dom/token_list.c; #16876). */
final class TokenListCount extends DomClassMethod
{
  public function __construct()
  {
    parent::__construct('count');
  }

  public function execute(Frame $frame): void
  {
    $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::count()');
    if (null === $frame->returnVar) {
      return;
    }
    $frame->returnVar->int(VmDomTokenList::length($receiver));
  }
}
