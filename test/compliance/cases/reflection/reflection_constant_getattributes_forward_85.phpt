--TEST--
ReflectionConstant::getAttributes() on PROFILE=8.5 (#28157, ext/reflection/php_reflection.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
#[\Attribute]
class Attr28157f {}
#[Attr28157f]
const C28157_FWD = 42;
$c = new ReflectionConstant('C28157_FWD');
echo 'getAttributes=', method_exists($c, 'getAttributes') ? '1' : '0', "\n";
$attrs = $c->getAttributes();
echo count($attrs), ':', $attrs[0]->getName(), '=', $c->getValue(), "\n";
$plain = new ReflectionConstant('PHP_VERSION');
echo 'plain=', count($plain->getAttributes()), "\n";
--EXPECT--
getAttributes=1
1:Attr28157f=42
plain=0
