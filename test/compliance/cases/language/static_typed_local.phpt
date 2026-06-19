--TEST--
Language: typed function-local static variables (#10084, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

function inc(): void {
    static int $n = 0;
    $n++;
    echo $n, "\n";
}
inc();
inc();

function bad(): void {
    static string $s = 'ok';
    $s = 1;
}
try { bad(); } catch (Throwable $e) { echo get_class($e), "\n"; }
--EXPECT--
1
2
TypeError
