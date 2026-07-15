--TEST--
ReflectionParameter::getAttributes() on #[\SensitiveParameter] params (#5152, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

function f(#[\SensitiveParameter] $p) {}

$rp = (new ReflectionFunction('f'))->getParameters()[0];
$attr = $rp->getAttributes()[0];
echo $attr->getName(), "\n";
var_dump($attr instanceof ReflectionAttribute);
?>
--EXPECT--
SensitiveParameter
bool(true)
