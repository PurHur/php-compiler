--TEST--
Language: BackedEnum::from()/tryFrom() float coerce + E_DEPRECATED (#22947, zend_enum.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if ($no === E_DEPRECATED) {
        echo 'DEP:', $str, "\n";
        return true;
    }
    return false;
});
enum Num: int { case One = 1; }
enum Suit: string { case Hearts = 'H'; case One = '1'; }
echo 'tryLossy=';
var_export(Num::tryFrom(1.7));
echo "\n";
echo 'fromExact=';
var_export(Num::from(1.0));
echo "\n";
echo 'fromLossy=';
var_export(Num::from(1.7));
echo "\n";
echo 'suitTry=';
var_export(Suit::tryFrom(1.5));
echo "\n";
echo 'suitFromHearts=';
try {
    var_export(Suit::from(2.5));
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
echo "\n";
echo 'nan=';
try {
    var_export(Num::tryFrom(NAN));
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
echo "\n";
--EXPECT--
tryLossy=DEP:Implicit conversion from float 1.7 to int loses precision
\Num::One
fromExact=\Num::One
fromLossy=DEP:Implicit conversion from float 1.7 to int loses precision
\Num::One
suitTry=DEP:Implicit conversion from float 1.5 to int loses precision
\Suit::One
suitFromHearts=DEP:Implicit conversion from float 2.5 to int loses precision
ValueError:"2" is not a valid backing value for enum Suit
nan=TypeError:Num::tryFrom(): Argument #1 ($value) must be of type int, float given
