--TEST--
ReflectionProperty/ReflectionParameter::isDeprecated() — skipped: phantoms vs php-src (#28529 / #23701)
--SKIPIF--
<?php
die('skip ReflectionProperty/Parameter::isDeprecated absent from php-src (#28529); #[\Deprecated] cannot target property/parameter');
?>
--FILE--
<?php
echo "unreachable\n";
--EXPECT--
unreachable
