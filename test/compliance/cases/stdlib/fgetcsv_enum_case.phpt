--TEST--
stdlib fgetcsv() — enum case separator must TypeError (#6204, ext/standard/file.c, php-src-strict)
--FILE--
<?php
enum Sep: string { case Comma = ','; }
$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);
try {
    fgetcsv($f, 0, Sep::Comma);
    echo "accepted\n";
} catch (TypeError $e) {
    echo 'te: ', $e->getMessage(), "\n";
}
--EXPECT--
te: fgetcsv(): Argument #3 ($separator) must be of type string, Sep given
