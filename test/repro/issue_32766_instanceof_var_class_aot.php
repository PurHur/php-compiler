<?php
class A {}
$o = new A;
$n = 'A';
var_dump($o instanceof A);
var_dump($o instanceof $n);
