<?php
class A {}
class B extends A {}
$name = 'C';
class C {}
var_dump(is_a('C', 'C', true));
var_dump(is_a($name, 'C', true));
var_dump(is_subclass_of('B', 'A'));
var_dump(is_a('C', 'A', true));
