--TEST--
stdlib iconv() null $string — TypeError not empty-string coerce (#18242, ext/iconv/iconv.c)
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
