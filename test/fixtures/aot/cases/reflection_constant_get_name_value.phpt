--TEST--
AOT: ReflectionConstant getName/getValue match VM for PHP_VERSION_ID (#27303)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$c = new ReflectionConstant('PHP_VERSION_ID');
echo $c->getName(), ' ', $c->getValue(), "\n";
?>
--EXPECTF--
PHP_VERSION_ID %d
