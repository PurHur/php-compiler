--TEST--
stdlib DateTime getMicrosecond/setMicrosecond forward 8.4 profile (#22374, #7082)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
$dt = new DateTime('2024-06-01 12:34:56.789012');
echo $dt->getMicrosecond(), "\n";
$dt->setMicrosecond(123456);
echo $dt->format('u'), "\n";

$immutable = DateTimeImmutable::createFromFormat('U.u', '1717242896.654321');
echo $immutable->getMicrosecond(), "\n";

$updated = $immutable->setMicrosecond(111111);
echo ($updated === $immutable ? "same\n" : "new\n");
echo $updated->getMicrosecond(), "\n";

try {
    $dt->setMicrosecond(1_000_000);
    echo "no-throw\n";
} catch (DateRangeError $e) {
    echo "range\n";
}
?>
--EXPECT--
789012
123456
654321
new
111111
range
