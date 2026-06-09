<?php

declare(strict_types=1);

class T {
    public static function s(): void {}
    protected function p(): void {}
}

$rmStatic = new ReflectionMethod(T::class, 's');
$rmInst = new ReflectionMethod(T::class, 'p');

var_export([
    'static_isStatic' => $rmStatic->isStatic(),
    'inst_isStatic' => $rmInst->isStatic(),
    'isPublic' => $rmInst->isPublic(),
    'isProtected' => $rmInst->isProtected(),
    'getModifiers' => $rmInst->getModifiers(),
]);
