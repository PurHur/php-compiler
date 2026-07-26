<?php
class P{public $u='U';}
function f($a,$b){ echo "$a|$b\n"; }
$x=5; $p=new P; f($x+1,$p->u);
