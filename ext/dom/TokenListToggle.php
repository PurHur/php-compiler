<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** DOMTokenList::toggle() — VM (php-src ext/dom/token_list.c; #16876). */
final class TokenListToggle extends DomClassMethod
{
  public function __construct()
  {
    parent::__construct('toggle');
  }

  public function execute(Frame $frame): void
  {
    $receiver = $this->receiver($frame, VmDom::CLASS_TOKEN_LIST, 'DOMTokenList::toggle()');
    if (\count($frame->calledArgs) < 2) {
      throw new \LogicException('DOMTokenList::toggle() expects at least 1 argument');
    }
    $token = $this->stringArg($frame->calledArgs[1], 'DOMTokenList::toggle()', 0);
    $force = null;
    if (isset($frame->calledArgs[2])) {
      $force = self::nullableBoolArg($frame->calledArgs[2], 'DOMTokenList::toggle()', 1);
    }
    if (null === $frame->returnVar) {
      return;
    }
    $frame->returnVar->bool(VmDomTokenList::toggle($frame->vmContext, $receiver, $token, $force));
  }

  private static function nullableBoolArg(Variable $var, string $label, int $index): ?bool
  {
    $var = $var->resolveIndirect();
    if (Variable::TYPE_NULL === $var->type) {
      return null;
    }
    if (Variable::TYPE_BOOLEAN !== $var->type) {
      throw new \TypeError(sprintf(
        '%s expects argument #%d to be of type ?bool, %s given',
        $label,
        $index + 1,
        VmDom::typeLabel($var)
      ));
    }

    return $var->toBool();
  }
}
