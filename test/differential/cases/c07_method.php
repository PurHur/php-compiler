<?php
class M{ function g($v){return "m$v";} }
function f($a,$b){ echo "$a $b\n"; }
$m=new M; f($m->g(1),$m->g(2));
