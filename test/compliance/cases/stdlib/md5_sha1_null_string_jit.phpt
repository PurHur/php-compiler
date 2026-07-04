--TEST--
stdlib md5()/sha1() null $string — TypeError JIT (#16139, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

foreach (['md5', 'sha1'] as $fn) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
md5(): Argument #1 ($string) must be of type string, null given
sha1(): Argument #1 ($string) must be of type string, null given
