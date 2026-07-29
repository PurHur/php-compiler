<?php
function f(int $a = 1) {}
$p = (new ReflectionFunction('f'))->getParameters()[0];
echo 'isNamed=', method_exists($p, 'isNamed') ? 'yes' : 'no', "\n";
echo 'getValue=', method_exists($p, 'getValue') ? 'yes' : 'no', "\n";
