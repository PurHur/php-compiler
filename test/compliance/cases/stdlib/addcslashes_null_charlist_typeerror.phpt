--TEST--
stdlib addcslashes() null $charlist — TypeError not silent coerce (#16102, ext/standard/string.c)
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
addcslashes: TypeError: addcslashes(): Argument #2 ($charlist) must be of type string, null given
