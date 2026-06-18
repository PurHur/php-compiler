--TEST--
Language: BackedEnum::tryFrom()/from() int backing + identity (#9685, zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
var_dump(E::tryFrom(1));
var_dump(E::tryFrom(99));
try {
    var_dump(E::from(1));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    E::from(99);
} catch (ValueError $e) {
    echo "ValueError ok\n";
}
var_export(E::tryFrom(1) === E::A);
echo "\n";
--EXPECT--
enum(E::A)
NULL
enum(E::A)
ValueError ok
true
