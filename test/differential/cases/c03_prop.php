<?php
class P{public $u='U';public $v='V';}
function f($a,$b){ echo "$a $b\n"; }
$p=new P; f($p->u,$p->v);
