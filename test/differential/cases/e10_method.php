<?php class C{ public $p="P"; function m($v){return "m$v";} } $c=new C; echo implode("|",[$c->m(1),$c->m(2)]), "\n"; echo str_repeat($c->p, 2), "\n";
