<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMTokenList::replace() — VM (php-src ext/dom/token_list.c; #16876). */
final class TokenListReplace extends DomClassMethod
{
  public function __construct()
  {
    parent::__construct('replace');
  }

  public function execute(Frame $frame): void
  {
    $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::replace()');
    if (\count($frame->calledArgs) < 3) {
      throw new \LogicException('DOMTokenList::replace() expects at least 2 arguments');
    }
    $token = $this->stringArg($frame->calledArgs[1], 'DOMTokenList::replace()', 0);
    $newToken = $this->stringArg($frame->calledArgs[2], 'DOMTokenList::replace()', 1);
    if (null === $frame->returnVar) {
      return;
    }
    $frame->returnVar->bool(VmDomTokenList::replace($frame->vmContext, $receiver, $token, $newToken));
  }
}
