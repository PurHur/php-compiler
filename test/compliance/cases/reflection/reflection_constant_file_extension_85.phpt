--TEST--
ReflectionConstant getFileName/getExtension/getExtensionName on PROFILE=8.5 (#21551)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
define('Foo\\BAR', 1);
$r = new ReflectionConstant('Foo\\BAR');
echo method_exists($r, 'getFileName') ? "file-yes\n" : "file-no\n";
echo method_exists($r, 'getExtension') ? "ext-yes\n" : "ext-no\n";
echo method_exists($r, 'getExtensionName') ? "extName-yes\n" : "extName-no\n";
echo var_export($r->getFileName(), true), "\n";
echo var_export($r->getExtension(), true), "\n";
echo var_export($r->getExtensionName(), true), "\n";
$pi = new ReflectionConstant('PHP_VERSION');
echo var_export($pi->getFileName(), true), "\n";
echo var_export($pi->getExtensionName(), true), "\n";
$e = $pi->getExtension();
echo ($e === null) ? "null\n" : (get_class($e) . ':' . $e->getName() . "\n");
--EXPECT--
file-yes
ext-yes
extName-yes
'Command line code'
NULL
false
false
'Core'
ReflectionExtension:Core
