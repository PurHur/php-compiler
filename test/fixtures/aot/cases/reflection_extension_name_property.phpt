--TEST--
AOT ReflectionExtension $name and getName (#34008)
--FILE--
<?php
$r = new ReflectionExtension('standard');
echo $r->name, '|', $r->getName(), PHP_EOL;
--EXPECT--
standard|standard
