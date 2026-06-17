--TEST--
Language: enum case pseudo-properties work through nullsafe ?-> and ordinary -> (#9171)
--FILE--
<?php

enum E: int { case A = 1; }

$null = null;
var_export($null?->x);
echo "\n";

var_export(E::A?->name);
echo "\n";
var_export(E::A?->value);
echo "\n";

var_export(E::A->name);
echo "\n";
var_export(E::A->value);
echo "\n";
?>
--EXPECT--
NULL
'A'
1
'A'
1

