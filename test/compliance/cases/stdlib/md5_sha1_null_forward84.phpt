--TEST--
stdlib md5()/sha1() null $string — TypeError on 8.4 forward profile (#19255, ext/standard/md5.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['md5', 'sha1'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
echo var_export(md5(''), true), "\n";
?>
--EXPECT--
md5(): Argument #1 ($string) must be of type string, null given
sha1(): Argument #1 ($string) must be of type string, null given
'd41d8cd98f00b204e9800998ecf8427e'
