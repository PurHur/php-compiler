<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;

/** DOMTokenList::supports() — VM (php-src ext/dom/token_list.c; #16876). */
final class TokenListSupports extends DomClassMethod
{
  public function __construct()
  {
    parent::__construct('supports');
  }

  public function execute(Frame $frame): void
  {
    $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::supports()');
    if (\count($frame->calledArgs) < 2) {
      throw new \LogicException('DOMTokenList::supports() expects at least 1 argument');
    }
    throw new \TypeError('Attribute "class" does not define any supported tokens');
  }
}
