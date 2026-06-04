<?php
$x = 1;
unset($x);
var_dump((bool) $x);
var_dump(isset($x));
