--TEST--
image getimagesizefromstring(null) — TypeError on default profile JIT (#19003, ext/standard/image.c)
--JIT--
--FILE--
<?php
try {
    getimagesizefromstring(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
getimagesizefromstring(): Argument #1 ($string) must be of type string, null given
