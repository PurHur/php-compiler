--TEST--
stdlib checkdate() typed int args reject numeric strings (#12215, ext/standard/datetime.c)
--FILE--
<?php
try {
    checkdate('2', 29, 2020);
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo checkdate(2, 29, 2020) ? 'valid' : 'invalid', "\n";
echo checkdate(2, 30, 2020) ? 'valid' : 'invalid', "\n";
--EXPECT--
checkdate(): Argument #1 ($month) must be of type int, string given
valid
invalid
