--TEST--
image getimagesizefromstring(null) — TypeError on default profile (#19003, ext/standard/image.c)
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
