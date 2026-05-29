--TEST--
Language: stdClass builtin — dynamic properties and (object) cast (#3117)
--FILE--
<?php
$o = new stdClass();
$o->x = 1;
echo $o->x, "\n";
echo get_class($o), "\n";
var_export($o instanceof stdClass);
echo "\n";

$fromArray = (object) ['a' => 2, 'b' => 'hi'];
echo $fromArray->a, "\n";
echo $fromArray->b, "\n";
var_export($fromArray instanceof stdClass);
echo "\n";
--EXPECT--
1
stdClass
true
2
hi
true
