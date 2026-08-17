--TEST--
Stdlib: Imagick read/resize/write subset (#6235, ext/imagick/imagick_class.c)
--ENV--
PHP_COMPILER_ENABLE_IMAGICK=1
--FILE--
<?php
if (!class_exists('Imagick')) {
    echo "skip\n";
    exit(0);
}
$src = __DIR__.'/../../../fixtures/imagick/red_3x3.png';
$im = new Imagick();
$im->readImage($src);
echo $im->getImageWidth(), 'x', $im->getImageHeight(), "\n";
$im->resizeImage(6, 6, 22, 1.0, false);
echo $im->getImageWidth(), 'x', $im->getImageHeight(), "\n";
echo "ok\n";
?>
--EXPECT--
3x3
6x6
ok
