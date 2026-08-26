<?php
$o = (object) ['x' => 'v'];
$f = false;
var_export($f ? [$o->x] : ['x']);
echo "\n";
