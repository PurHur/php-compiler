<?php
class A {}
class B extends A {}
$name = 'B';
var_dump(is_a('B', 'A', true));
var_dump(is_a($name, 'A', true));
var_dump(is_a('B', 'B', true));
var_dump(is_a('B', 'A', false));
var_dump(is_subclass_of('B', 'A'));
var_dump(is_subclass_of($name, 'A'));
var_dump(is_subclass_of('A', 'B'));
