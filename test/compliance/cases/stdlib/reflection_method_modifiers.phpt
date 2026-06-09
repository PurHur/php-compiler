--TEST--
Stdlib: ReflectionMethod modifier introspection (VM, #7116)
--FILE--
<?php
declare(strict_types=1);

class T {
    public static function s(): void {}
    protected function p(): void {}
    final public function f(): void {}
}

$rmStatic = new ReflectionMethod(T::class, 's');
$rmInst = new ReflectionMethod(T::class, 'p');
$rmFinal = new ReflectionMethod(T::class, 'f');

var_export([
    'static_isStatic' => $rmStatic->isStatic(),
    'inst_isStatic' => $rmInst->isStatic(),
    'static_isPublic' => $rmStatic->isPublic(),
    'inst_isPublic' => $rmInst->isPublic(),
    'inst_isProtected' => $rmInst->isProtected(),
    'inst_isPrivate' => $rmInst->isPrivate(),
    'final_isFinal' => $rmFinal->isFinal(),
    'static_getModifiers' => $rmStatic->getModifiers(),
    'inst_getModifiers' => $rmInst->getModifiers(),
    'final_getModifiers' => $rmFinal->getModifiers(),
]);
--EXPECT--
array (
  'static_isStatic' => true,
  'inst_isStatic' => false,
  'static_isPublic' => true,
  'inst_isPublic' => false,
  'inst_isProtected' => true,
  'inst_isPrivate' => false,
  'final_isFinal' => true,
  'static_getModifiers' => 17,
  'inst_getModifiers' => 2,
  'final_getModifiers' => 33,
)
