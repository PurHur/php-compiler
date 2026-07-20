--TEST--
ReflectionConstant getNamespaceName/getShortName/__toString (#21551, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
define('Foo\\BAR', 1);
define('GLOBAL_C', 2);
$r = new ReflectionConstant('Foo\\BAR');
echo method_exists($r, 'getNamespaceName') ? "ns-yes\n" : "ns-no\n";
echo method_exists($r, 'getShortName') ? "short-yes\n" : "short-no\n";
echo method_exists($r, '__toString') ? "str-yes\n" : "str-no\n";
echo method_exists($r, 'inNamespace') ? "inNs-yes\n" : "inNs-no\n";
echo method_exists($r, 'getFileName') ? "file-yes\n" : "file-no\n";
echo $r->getNamespaceName(), "\n";
echo $r->getShortName(), "\n";
echo (string) $r;
$g = new ReflectionConstant('GLOBAL_C');
echo '[' . $g->getNamespaceName() . "]\n";
echo $g->getShortName(), "\n";
echo (string) $g;
$pi = new ReflectionConstant('PHP_VERSION');
echo '[' . $pi->getNamespaceName() . "]\n";
echo $pi->getShortName(), "\n";
$s = (string) $pi;
echo (str_contains($s, '<persistent>') && str_contains($s, 'string PHP_VERSION') && str_contains($s, '{ ')) ? "persistent-ok\n" : "persistent-bad\n";
--EXPECT--
ns-yes
short-yes
str-yes
inNs-no
file-no
Foo
BAR
Constant [ int Foo\BAR ] { 1 }
[]
GLOBAL_C
Constant [ int GLOBAL_C ] { 2 }
[]
PHP_VERSION
persistent-ok
