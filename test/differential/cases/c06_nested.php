<?php
function g($v){ return $v*2; }
function f($a,$b){ echo "$a $b\n"; }
f(g(1),g(2));
