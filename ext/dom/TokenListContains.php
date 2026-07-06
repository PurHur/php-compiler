<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMTokenList::contains() — VM (php-src ext/dom/token_list.c; #16876). */
final class TokenListContains extends DomClassMethod
{
  public function __construct()
  {
    parent::__construct('contains');
  }

  public function execute(Frame $frame): void
  {
    $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::contains()');
    if (\count($frame->calledArgs) < 2) {
      throw new \LogicException('DOMTokenList::contains() expects at least 1 argument');
    }
    $token = $this->stringArg($frame->calledArgs[1], 'DOMTokenList::contains()', 0);
    if (null === $frame->returnVar) {
      return;
    }
    $frame->returnVar->bool(VmDomTokenList::contains($receiver, $token));
  }
}
