--TEST--
Language: typed function-local static variables (issue #9998, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

function counter(): void {
    static int $n = 0;
    $n++;
    echo $n, "\n";
}
counter();
counter();

function nullable(): void {
    static ?int $x = null;
    if (null === $x) {
        $x = 1;
        echo "init\n";
    }
    $x++;
    echo $x, "\n";
}
nullable();
nullable();

function typed_array(): void {
    static array $a = [1];
    echo count($a), "\n";
    try {
        $a = 'x';
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
typed_array();
--EXPECT--
1
2
init
2
3
1
TypeError: Cannot assign string to static variable $a of type array
