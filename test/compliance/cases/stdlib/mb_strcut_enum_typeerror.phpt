--TEST--
stdlib mb_strcut() — backed enum case TypeError (#4573, php-src-strict)
--FILE--
<?php
enum Es: string { case B = 'hi'; }
try {
    mb_strcut(Es::B, 0, 1);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mb_strcut(): Argument #1 ($string) must be of type string, Es given
