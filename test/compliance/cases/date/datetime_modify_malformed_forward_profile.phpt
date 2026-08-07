--TEST--
date DateTime(Immutable)::modify invalid string throws DateMalformedStringException on forward 8.3+ profile (#22663, #28524, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(class_exists('DateMalformedStringException', false));
echo "\n";
$im = new DateTimeImmutable('2024-01-01');
try {
    $im->modify('not a date');
    echo "immutable no throw\n";
} catch (DateMalformedStringException $e) {
    echo 'immutable:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
    echo $im->format('Y-m-d'), "\n";
}
$dt = new DateTime('2024-01-01');
try {
    $dt->modify('not a date');
    echo "mutable no throw\n";
} catch (DateMalformedStringException $e) {
    echo 'mutable:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
    echo $dt->format('Y-m-d'), "\n";
}
$proc = new DateTime('2024-01-01');
$bad = @date_modify($proc, 'not a date');
var_export([$bad, $proc->format('Y-m-d')]);
echo "\n";
--EXPECT--
true
immutable:DateMalformedStringException
DateTimeImmutable::modify(): Failed to parse time string (not a date) at position 0 (n): The timezone could not be found in the database
2024-01-01
mutable:DateMalformedStringException
DateTime::modify(): Failed to parse time string (not a date) at position 0 (n): The timezone could not be found in the database
2024-01-01
array (
  0 => false,
  1 => '2024-01-01',
)
