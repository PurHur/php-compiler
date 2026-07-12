--TEST--
stdlib getimagesize() / getimagesizefromstring() — 1×1 JPEG metadata (#17455, ext/standard/image.c)
--FILE--
<?php
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=');
$path = sys_get_temp_dir() . '/phpc-getimagesize-jpeg-' . getmypid() . '.jpg';
file_put_contents($path, $jpeg);
$fromFile = getimagesize($path);
$fromString = getimagesizefromstring($jpeg);
@unlink($path);
echo ($fromFile[0] ?? 'x') . 'x' . ($fromFile[1] ?? 'x') . "\n";
echo ($fromFile[2] ?? 'x') . "\n";
echo ($fromFile['mime'] ?? 'x') . "\n";
echo ($fromString[0] ?? 'x') . 'x' . ($fromString[1] ?? 'x') . "\n";
echo ($fromString['mime'] ?? 'x') . "\n";
?>
--EXPECT--
1x1
2
image/jpeg
1x1
image/jpeg
