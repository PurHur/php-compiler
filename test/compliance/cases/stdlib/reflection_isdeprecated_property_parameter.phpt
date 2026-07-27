--TEST--
ReflectionProperty/ReflectionParameter::isDeprecated() — skipped: Zend rejects #[\Deprecated] on property/parameter (#9768 / #23701)
--SKIPIF--
<?php
die('skip #[\Deprecated] cannot target property/parameter (Zend 8.4/8.5, #23701); ReflectionProperty::isDeprecated absent on php-src 8.4');
?>
--FILE--
<?php
echo "unreachable\n";
--EXPECT--
unreachable
