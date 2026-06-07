<?php
$o = new stdClass();
$o->{1} = 'a';
var_export((array) $o);

echo "\n";

var_export((array) (object) [1 => 'a']);
