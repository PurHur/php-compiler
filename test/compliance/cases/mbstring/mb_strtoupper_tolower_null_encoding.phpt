--TEST--
mbstring mb_strtoupper/tolower(null $encoding) uses internal encoding (#31312, mbstring.stub.php)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    echo var_export(mb_strtoupper('ab', null), true), "\n";
    echo var_export(mb_strtolower('AB', null), true), "\n";
    echo var_export(mb_strtoupper('ab'), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
'AB'
'ab'
'AB'
