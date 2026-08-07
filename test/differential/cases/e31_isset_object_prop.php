<?php
$c=new stdClass;$c->a=1;$c->b=null;
var_export(isset($c->a));echo "\n";
var_export(isset($c->b));echo "\n";
var_export(isset($c->c));echo "\n";
