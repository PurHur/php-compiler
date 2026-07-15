--TEST--
stdlib fputcsv() null $fields TypeError (#19214, ext/standard/formatted_io.c)
--FILE--
<?php
$fp = fopen('php://memory', 'w+');
try {
    fputcsv($fp, null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
fputcsv(): Argument #2 ($fields) must be of type array, null given
