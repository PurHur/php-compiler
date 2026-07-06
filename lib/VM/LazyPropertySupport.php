<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\TypeCheck;
use PHPCompiler\VM;

/**
 * PHP 8.4 declarative `lazy` property deferred initialization (#16813).
 *
 * php-src: Zend/zend_compile.c ZEND_ACC_LAZY; zend_lazy_objects.c.
 */
final class LazyPropertySupport
{
  public static function ensureDeclarativeLazyPropertyInitialized(
    VM $vm,
    ObjectEntry $object,
    string $propertyName
  ): void {
    $meta = self::classPropertyMeta($object, $propertyName);
    if (null === $meta || !$meta->lazy) {
      return;
    }
    if (isset($object->lazyRawInitializedProperties[$propertyName])) {
      return;
    }
    $slot = $object->getProperty($propertyName);
    if (!TypedPropertyCheck::isUninitialized($slot) && !$slot->isUndefined()) {
      $object->lazyRawInitializedProperties[$propertyName] = true;

      return;
    }
    if ($meta->hasRuntimeDefaultInit()) {
      $value = $vm->evaluatePropertyDefaultForReflection($meta);
      if (null === $value) {
        return;
      }
      $slot->copyFrom($value);
    } elseif (null !== $meta->default) {
      $slot->copyFrom($meta->default);
    } else {
      $slot->copyFrom($meta->prototype);
    }
    TypeCheck::coercePropertyWrite($slot, false);
    $object->lazyRawInitializedProperties[$propertyName] = true;
  }

  public static function isDeclarativeLazyProperty(
    ObjectEntry $object,
    string $propertyName
  ): bool {
    $meta = self::classPropertyMeta($object, $propertyName);
    if (null === $meta || !$meta->lazy) {
      return false;
    }
    if (isset($object->lazyRawInitializedProperties[$propertyName])) {
      return false;
    }
    $slot = $object->getProperty($propertyName);

    return TypedPropertyCheck::isUninitialized($slot) || $slot->isUndefined();
  }

  private static function classPropertyMeta(ObjectEntry $object, string $propertyName): ?ClassProperty
  {
    foreach ($object->class->properties as $prop) {
      if ($prop->name === $propertyName) {
        return $prop;
      }
    }

    return null;
  }
}
