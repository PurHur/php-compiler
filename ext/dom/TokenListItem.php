<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMTokenList::item() — VM (php-src ext/dom/token_list.c; #16876). */
final class TokenListItem extends DomClassMethod
{
  public function __construct()
  {
    parent::__construct('item');
  }

  public function execute(Frame $frame): void
  {
    $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::item()');
    if (\count($frame->calledArgs) < 2) {
      throw new \LogicException('DOMTokenList::item() expects at least 1 argument');
    }
    $indexVar = $frame->calledArgs[1]->resolveIndirect();
    if (Variable::TYPE_INTEGER !== $indexVar->type && Variable::TYPE_FLOAT !== $indexVar->type) {
      throw new \TypeError(sprintf(
        'DOMTokenList::item(): Argument #1 ($index) must be of type int, %s given',
        VmDom::typeLabel($indexVar)
      ));
    }
    if (null === $frame->returnVar) {
      return;
    }
    $item = VmDomTokenList::item($receiver, $indexVar->toInt());
    if (null === $item) {
      $frame->returnVar->null();
    } else {
      $frame->returnVar->string($item);
    }
  }
}
