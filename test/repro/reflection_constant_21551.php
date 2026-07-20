<?php
define('Foo\\BAR', 1);
define('GLOBAL_C', 2);
$r = new ReflectionConstant('Foo\\BAR');
echo $r->getName(), "\n";
echo $r->getNamespaceName(), "\n";
echo $r->getShortName(), "\n";
echo (string) $r;
$g = new ReflectionConstant('GLOBAL_C');
echo '[' . $g->getNamespaceName() . "]\n";
echo $g->getShortName(), "\n";
$pi = new ReflectionConstant('PHP_VERSION');
$s = (string) $pi;
echo (str_contains($s, 'PHP_VERSION') && (str_contains($s, '<persistent>') || str_contains($s, 'mixed'))) ? "persistent-ok\n" : "persistent-bad\n";
echo method_exists($r, 'inNamespace') ? "inNs-yes\n" : "inNs-no\n";
