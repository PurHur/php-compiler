--TEST--
mbstring mb_convert_encoding(null $from_encoding) uses internal encoding (#31488, mbstring.stub.php)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    echo var_export(mb_convert_encoding('a', 'UTF-8', null), true), "\n";
    echo var_export(mb_convert_encoding('a', 'UTF-8'), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
'a'
'a'
