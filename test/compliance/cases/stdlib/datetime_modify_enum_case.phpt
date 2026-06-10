--TEST--
stdlib DateTime::modify() — enum case $modifier must TypeError (#6132, ext/date/php_date.c, php-src-strict)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case MOD = '+1 day';
}

$dt = new DateTime('2020-01-01');
try {
    $dt->modify(E::MOD);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$dt2 = new DateTime('2020-01-01', new DateTimeZone('UTC'));
$dt2->modify('+1 day');
echo $dt2->format('Y-m-d'), "\n";
?>
--EXPECT--
TypeError: DateTime::modify(): Argument #1 ($modifier) must be of type string, E given
2020-01-02
