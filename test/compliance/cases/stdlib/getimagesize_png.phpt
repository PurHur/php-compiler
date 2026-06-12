--TEST--
stdlib getimagesize() / getimagesizefromstring() — 1×1 PNG metadata (#3271)
--FILE--
<?php
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$path = sys_get_temp_dir() . '/phpc-getimagesize-' . getmypid() . '.png';
file_put_contents($path, $png);
$fromFile = getimagesize($path);
$fromString = getimagesizefromstring($png);
@unlink($path);
echo function_exists('getimagesize') ? "exists\n" : "missing\n";
echo ($fromFile[0] ?? 'x') . 'x' . ($fromFile[1] ?? 'x') . "\n";
echo ($fromFile[2] ?? 'x') . "\n";
echo ($fromFile['mime'] ?? 'x') . "\n";
echo ($fromString[0] ?? 'x') . 'x' . ($fromString[1] ?? 'x') . "\n";
echo ($fromString['mime'] ?? 'x') . "\n";
?>
--EXPECT--
exists
1x1
3
image/png
1x1
image/png
