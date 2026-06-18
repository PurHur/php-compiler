--TEST--
Language: list destructuring with enum-case array key must TypeError (#9713, zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; }

try {
    [$x] = [E::A => 'v'];
    echo "short-fail\n";
} catch (Throwable $e) {
    echo 'short: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    list($x) = [E::A => 'v'];
    echo "list-fail\n";
} catch (Throwable $e) {
    echo 'list: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    ['a' => $x] = [E::A => 'v'];
    echo "keyed-fail\n";
} catch (Throwable $e) {
    echo 'keyed: ', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
short: TypeError: Illegal offset type
list: TypeError: Illegal offset type
keyed: TypeError: Illegal offset type
