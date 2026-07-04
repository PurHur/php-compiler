--TEST--
stdlib addcslashes() null $characters — TypeError not silent coerce (#16159, ext/standard/string.c)
--FILE--
<?php
try {
    addcslashes('abc', null);
    echo "addcslashes: ok\n";
} catch (Throwable $e) {
    echo 'addcslashes: ', $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
addcslashes: TypeError: addcslashes(): Argument #2 ($characters) must be of type string, null given
