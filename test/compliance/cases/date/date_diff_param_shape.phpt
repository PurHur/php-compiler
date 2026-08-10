--TEST--
date_diff() TypeError cites $targetObject/DateTimeInterface (#29861, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
try {
    date_diff(date_create('@0'), null);
    echo "null:fail\n";
} catch (Throwable $e) {
    echo 'null:', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    $a = new DateTimeImmutable('@0');
    $b = new DateTime('@86400');
    echo 'imm:', date_diff($a, $b)->days, "\n";
} catch (Throwable $e) {
    echo 'imm:', get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
null:TypeError
date_diff(): Argument #2 ($targetObject) must be of type DateTimeInterface, null given
imm:1
