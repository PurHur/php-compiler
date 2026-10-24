<?php
function f($a,$b){return $a+$b;}
function m($a,$b){return $a*$b;}
var_dump(f("2","3"), f(" 2","3"), f("2 ","3"), f("1e3",1), f("9223372036854775807","1"), f("1.5","1"), m("4","5"), f(2,"3"), f("-7", 0));
var_dump("10" - "4", "3" * "3", "2" + 1.5);
try { var_dump(f("abc",1)); } catch (TypeError $e) { echo get_class($e),": ",$e->getMessage(),"\n";}
