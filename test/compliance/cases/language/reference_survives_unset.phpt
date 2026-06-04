--TEST--
Language: reference alias survives unset of source variable (#5368)
--FILE--
<?php
$a = 1;
$b =& $a;
unset($a);
var_export($b);
echo "\n";
$o = new stdClass;
$o->p = 1;
$c =& $o;
unset($o);
$c->p = 2;
var_export($c->p);
echo "\n";
--EXPECT--
1
2
