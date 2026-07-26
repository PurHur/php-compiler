<?php class P{ function __construct(public $a, public $b){} } $x=1; $p=new P($x+1,$x+2); echo "$p->a|$p->b\n";
