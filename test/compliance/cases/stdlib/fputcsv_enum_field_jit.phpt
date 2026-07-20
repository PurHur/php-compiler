--TEST--
stdlib fputcsv() enum field → Error like Zend JIT (#5898, ext/standard/file.c)
--FILE--
<?php
enum E: string { case A = 'a'; }
$fp = fopen('php://memory', 'r+');
try {
    $n = fputcsv($fp, [E::A]);
    echo 'n=', $n, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
fclose($fp);
$fp2 = fopen('php://memory', 'r+');
try {
    fputcsv($fp2, [E::A]);
    echo "void_ok\n";
} catch (Throwable $e) {
    echo 'void:', get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
fclose($fp2);
--EXPECT--
Error: Object of class E could not be converted to string
void:Error: Object of class E could not be converted to string
