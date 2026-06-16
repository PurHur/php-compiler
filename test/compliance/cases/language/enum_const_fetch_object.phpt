--TEST--
Language: backed enum class const fetch yields enum case singleton objects (Zend/zend_enum.c, #9030)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
enum Status: string { case Active = 'a'; }

var_export(E::A);
echo "\n", get_debug_type(E::A), "\n";

function takesEnum(E $e): string { return $e->name; }
try {
    echo takesEnum(E::A), "\n";
} catch (Throwable $t) {
    echo get_class($t), "\n";
}

$arr = [E::A, E::B];
var_export($arr[0] === E::A);
echo "\n";

var_export(Status::Active);
echo "\n", get_debug_type(Status::Active), "\n";
--EXPECT--
\E::A
E
A
true
\Status::Active
Status
