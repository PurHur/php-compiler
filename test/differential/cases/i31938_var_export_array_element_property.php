<?php
// var_export($arr['key']->prop, true) must export the property, not the object (#31938).
$o = new stdClass();
$o->name = 'test';
$arr = ['o' => $o];
echo var_export($arr['o']->name, true), "\n";
$b = [1 => [0 => 'a']];
echo var_export($b[1][0], true), "\n";
