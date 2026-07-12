--TEST--
stdlib iconv() JIT — null $string TypeError (#18242, ext/iconv/iconv.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    iconv('UTF-8', 'ASCII//TRANSLIT', null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
iconv(): Argument #3 ($string) must be of type string, null given
