--TEST--
stdlib array_all()/array_any() — enum case JIT predicate callback (#5722)
--JIT--
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }

$allOk = array_all([E::A, E::B], function ($v) {
    return $v === E::A || $v === E::B;
});
var_export($allOk);
echo "\n";

$capturedAny = null;
$anyOk = array_any([E::A, E::B], function ($v) use (&$capturedAny) {
    $capturedAny = $v;
    return $v === E::A;
});
var_export($anyOk);
echo "\n";
var_export($capturedAny === E::A);
echo "\n";
?>
--EXPECT--
true
true
true
