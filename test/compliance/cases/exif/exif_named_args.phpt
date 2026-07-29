--TEST--
exif exif_imagetype/exif_thumbnail Reflection names + named args (#24458, #24457, ext/exif/exif.stub.php)
--FILE--
<?php
echo (new ReflectionFunction('exif_imagetype'))->getParameters()[0]->getName(), "\n";
$r = new ReflectionFunction('exif_thumbnail');
echo implode(',', array_map(fn ($p) => $p->getName(), $r->getParameters())), "\n";
try {
    @exif_imagetype(filename: '/no/such/file.jpg');
    echo "imagetype_named_ok\n";
} catch (Throwable $e) {
    echo 'imagetype_named_fail:', $e->getMessage(), "\n";
}
$w = $h = $t = null;
try {
    @exif_thumbnail(file: '/no/such/file.jpg', width: $w, height: $h, image_type: $t);
    echo "thumbnail_named_ok\n";
} catch (Throwable $e) {
    echo 'thumbnail_named_fail:', $e->getMessage(), "\n";
}
?>
--EXPECT--
filename
file,width,height,image_type
imagetype_named_ok
thumbnail_named_ok
