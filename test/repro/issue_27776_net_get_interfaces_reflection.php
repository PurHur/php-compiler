<?php
$r = new ReflectionFunction('net_get_interfaces');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
$x = net_get_interfaces();
echo 'type=', gettype($x), ' count=', is_array($x) ? count($x) : 'n/a', "\n";
