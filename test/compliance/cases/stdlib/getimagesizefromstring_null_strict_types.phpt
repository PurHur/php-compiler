--TEST--
stdlib getimagesizefromstring(null) — TypeError under declare(strict_types=1) (#19067, ext/standard/image.c)
--FILE--
<?php
declare(strict_types=1);
try {
    getimagesizefromstring(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
getimagesizefromstring(): Argument #1 ($string) must be of type string, null given
