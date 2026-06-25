<?php
enum E: int { case A = 1; }
class C { public E $e = E::A; }
$c = new C();
var_dump($c->e === E::A);
