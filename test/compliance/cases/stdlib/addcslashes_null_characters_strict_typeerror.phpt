--TEST--
stdlib addcslashes() null $characters under strict_types — TypeError (#17829, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
try {
    addcslashes('abc', null);
    echo "addcslashes: ok\n";
} catch (Throwable $e) {
    echo 'addcslashes: ', $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
addcslashes: TypeError: addcslashes(): Argument #2 ($characters) must be of type string, null given
