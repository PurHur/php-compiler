--TEST--
stdlib addcslashes() null $characters under strict_types JIT — TypeError (#17829, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
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
