--TEST--
stdlib class_uses_recursive() — not advertised on PHP 8.2 reference profile (#12816)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.2') {
    echo 'skip PHP_COMPILER_PROFILE=8.2 only';
}
?>
--FILE--
<?php
echo function_exists('class_uses_recursive') ? "fail\n" : "ok\n";
echo function_exists('class_uses') ? "uses-ok\n" : "uses-fail\n";
--EXPECT--
ok
uses-ok
