--TEST--
ReflectionFunction stub return types + tentative API for internals (#22068, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['strlen', 'count', 'array_keys', 'is_string'] as $name) {
    $rf = new ReflectionFunction($name);
    echo $name, ' has=', $rf->hasReturnType() ? '1' : '0';
    $rt = $rf->getReturnType();
    echo ' type=', $rt instanceof ReflectionNamedType ? $rt->getName() : 'NULL';
    echo "\n";
}

$rf = new ReflectionFunction('strlen');
echo 'hasTentativeMethod=', method_exists($rf, 'hasTentativeReturnType') ? '1' : '0', "\n";
echo 'hasTentative=', $rf->hasTentativeReturnType() ? '1' : '0', "\n";
echo 'getTentative=', null === $rf->getTentativeReturnType() ? 'NULL' : 'SET', "\n";

$closure = static function (): int { return 1; };
$rc = new ReflectionFunction($closure);
echo 'closure has=', $rc->hasReturnType() ? '1' : '0';
echo ' type=', $rc->getReturnType()?->getName() ?? 'NULL', "\n";
--EXPECT--
strlen has=1 type=int
count has=1 type=int
array_keys has=1 type=array
is_string has=1 type=bool
hasTentativeMethod=1
hasTentative=0
getTentative=NULL
closure has=1 type=int
