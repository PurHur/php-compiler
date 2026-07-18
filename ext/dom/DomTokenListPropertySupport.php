<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Computed DOMTokenList property bridge (php-src ext/dom/token_list.c; #16876). */
final class DomTokenListPropertySupport
{
  public static function isManagedProperty(ObjectEntry $object, string $name): bool
  {
    if (!VmDom::isTokenList($object)) {
      return false;
    }
    $lc = strtolower($name);

    return strtolower(VmDom::PROP_LENGTH) === $lc
      || strtolower(VmDom::PROP_VALUE) === $lc;
  }

  public static function getProperty(ObjectEntry $object, string $name): Variable
  {
    $lc = strtolower($name);
    $var = new Variable();
    $var->objectPropertyOwner = $object;
    $var->objectPropertyName = $lc;
    if (strtolower(VmDom::PROP_LENGTH) === $lc) {
      $var->int(VmDomTokenList::length($object));

      return $var;
    }
    if (strtolower(VmDom::PROP_VALUE) === $lc) {
      $var->string(VmDomTokenList::value($object));

      return $var;
    }

    throw new \LogicException('DomTokenListPropertySupport::getProperty() called with unmanaged name');
  }

  public static function tryAssign(
    ObjectEntry $owner,
    string $propName,
    Variable $value,
    Context $ctx
  ): bool {
    if (!self::isManagedProperty($owner, $propName)) {
      return false;
    }
    if (strtolower(VmDom::PROP_VALUE) !== strtolower($propName)) {
      return false;
    }
    $resolved = $value->resolveIndirect();
    if (Variable::TYPE_STRING !== $resolved->type) {
      throw new \TypeError(sprintf(
        'Cannot assign %s to property %s::$value of type string',
        VmDom::typeLabel($resolved),
        $owner->class->name
      ));
    }
    VmDomTokenList::setValue($ctx, $owner, $resolved->toString());

    return true;
  }
}
