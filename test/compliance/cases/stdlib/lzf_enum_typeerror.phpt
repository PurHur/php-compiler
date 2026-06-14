--TEST--
stdlib lzf_compress() — enum case operand TypeError (#6384, php-src-strict)
--FILE--
<?php
enum E: string
{
    case X = 'x';
}

try {
    lzf_compress(E::X);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
lzf_compress(): Argument #1 ($data) must be of type string, E given
