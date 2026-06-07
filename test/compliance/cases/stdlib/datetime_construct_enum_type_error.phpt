--TEST--
stdlib DateTime::__construct() — enum case $datetime must TypeError (#7162, ext/date/php_date.c, php-src-strict)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = '2020-01-01';
}

try {
    new DateTime(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: DateTime::__construct(): Argument #1 ($datetime) must be of type string, E given
