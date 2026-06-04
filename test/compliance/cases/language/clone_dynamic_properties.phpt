--TEST--
Language: clone copies dynamic properties on #[\AllowDynamicProperties] classes (#5435)
--FILE--
<?php
#[\AllowDynamicProperties]
class C {}

$o = new C();
$o->x = 1;
$c = clone $o;
var_dump($c->x);
--EXPECT--
int(1)
