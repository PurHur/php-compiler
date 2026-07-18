--TEST--
stdlib getimagesizefromstring(null) JIT — TypeError on 8.4 forward profile (#20353, re-#19100, ext/standard/image.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    getimagesizefromstring(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$size = getimagesizefromstring($png);
echo (int) $size[0], 'x', (int) $size[1], "\n";
?>
--EXPECT--
getimagesizefromstring(): Argument #1 ($string) must be of type string, null given
1x1
