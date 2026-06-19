--TEST--
PHP 8.4 pipe operator preserves backed enum case identity through call boundary (issue #10110)
--FILE--
<?php
enum E: int {
    case A = 1;
}
var_export(E::A |> (fn($x) => $x)());
echo "\n";
var_export(E::A |> (fn($x) => $x->name)());
echo "\n";
--EXPECT--
\E::A
'A'
