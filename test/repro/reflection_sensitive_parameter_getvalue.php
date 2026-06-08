<?php
function f(#[\SensitiveParameter] string $secret) {}
$r = new ReflectionFunction('f');
$p = $r->getParameters()[0];
$v = $p->getValue(['secret' => 'pw']);
var_export($v);
echo "\n";
var_export(get_debug_type($v));
