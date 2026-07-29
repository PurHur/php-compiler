<?php
function f(#[\SensitiveParameter] string $secret) {}
$r = new ReflectionFunction('f');
$p = $r->getParameters()[0];
echo method_exists($p, 'getValue') ? "getvalue=yes\n" : "getvalue=no\n";
$attrs = $p->getAttributes('SensitiveParameter');
echo count($attrs), "\n";
echo $attrs[0]->getName(), "\n";
