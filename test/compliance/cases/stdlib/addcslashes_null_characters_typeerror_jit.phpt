--TEST--
stdlib addcslashes() null $characters JIT — TypeError not silent coerce (#16159, ext/standard/string.c)
--FILE--
<?php
$ok = false;
try {
    addcslashes('abc', null);
} catch (TypeError $e) {
    $ok = ('addcslashes(): Argument #2 ($characters) must be of type string, null given' === $e->getMessage());
}
echo $ok ? "addcslashes TypeError\n" : "addcslashes no error\n";
?>
--EXPECT--
addcslashes TypeError
