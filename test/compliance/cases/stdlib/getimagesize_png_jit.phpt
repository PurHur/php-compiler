--TEST--
stdlib getimagesize() / getimagesizefromstring() JIT path (#3271)
--FILE--
<?php
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$path = sys_get_temp_dir() . '/phpc-getimagesize-jit-' . getmypid() . '.png';
file_put_contents($path, $png);
$fromFile = getimagesize($path);
$fromString = getimagesizefromstring($png);
@unlink($path);
echo ($fromFile[0] ?? 'x') . 'x' . ($fromFile[1] ?? 'x') . "\n";
echo ($fromFile['mime'] ?? 'x') . "\n";
echo ($fromString[0] ?? 'x') . 'x' . ($fromString[1] ?? 'x') . "\n";
echo ($fromString['mime'] ?? 'x') . "\n";
?>
--EXPECT--
1x1
image/png
1x1
image/png
