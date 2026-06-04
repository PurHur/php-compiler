--TEST--
Language: instanceof / is_a on backed enum case (#5548)
--FILE--
<?php
enum E: int { case A = 1; }
var_export(E::A instanceof E);
echo "\n";
var_export(is_a(E::A, E::class));
echo "\n";
foreach (E::cases() as $case) {
    var_export($case instanceof E);
    echo "\n";
    var_export(is_a($case, E::class));
    echo "\n";
}
--EXPECT--
true
true
true
true
