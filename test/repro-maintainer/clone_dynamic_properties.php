<?php
#[\AllowDynamicProperties]
class C {}

$o = new C();
$o->x = 1;
$c = clone $o;
var_dump($c->x);
