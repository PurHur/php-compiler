--TEST--
stdlib gd imagecreatefrompng/jpeg/gif + imagejpeg/imagegif round-trip (#20458, ext/gd/gd.c)
--FILE--
<?php
foreach ([
    'imagecreatefrompng', 'imagecreatefromjpeg', 'imagecreatefromgif',
    'imagejpeg', 'imagegif', 'imagepng',
] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}

$im = imagecreatetruecolor(3, 2);
$red = imagecolorallocate($im, 255, 0, 0);
$green = imagecolorallocate($im, 0, 255, 0);
imagefill($im, 0, 0, $red);
imagesetpixel($im, 1, 0, $green);

$dir = sys_get_temp_dir();
$pid = getmypid();

$png = $dir . '/phpc_gd_' . $pid . '.png';
$jpeg = $dir . '/phpc_gd_' . $pid . '.jpg';
$gif = $dir . '/phpc_gd_' . $pid . '.gif';

echo 'png_write=', (int) imagepng($im, $png), "\n";
echo 'jpeg_write=', (int) imagejpeg($im, $jpeg, 90), "\n";
echo 'gif_write=', (int) imagegif($im, $gif), "\n";

$fromPng = imagecreatefrompng($png);
$fromJpeg = imagecreatefromjpeg($jpeg);
$fromGif = imagecreatefromgif($gif);
@unlink($png);
@unlink($jpeg);
@unlink($gif);

echo 'png_type=', get_debug_type($fromPng), ' sx=', imagesx($fromPng), ' sy=', imagesy($fromPng), "\n";
echo 'png_c00=', imagecolorat($fromPng, 0, 0) & 0xFFFFFF, ' c10=', imagecolorat($fromPng, 1, 0) & 0xFFFFFF, "\n";
echo 'jpeg_type=', get_debug_type($fromJpeg), ' sx=', imagesx($fromJpeg), ' sy=', imagesy($fromJpeg), "\n";
echo 'jpeg_c00=', imagecolorat($fromJpeg, 0, 0) & 0xFFFFFF, ' c10=', imagecolorat($fromJpeg, 1, 0) & 0xFFFFFF, "\n";
echo 'gif_type=', get_debug_type($fromGif), ' sx=', imagesx($fromGif), ' sy=', imagesy($fromGif), "\n";
echo 'gif_c00=', imagecolorat($fromGif, 0, 0) & 0xFFFFFF, ' c10=', imagecolorat($fromGif, 1, 0) & 0xFFFFFF, "\n";

$infoJpeg = null;
$jpeg = $dir . '/phpc_gd_info_' . $pid . '.jpg';
imagejpeg($im, $jpeg);
$infoJpeg = getimagesize($jpeg);
@unlink($jpeg);
echo 'getimagesize_jpeg=', (int) $infoJpeg[0], 'x', (int) $infoJpeg[1], ' type=', (int) $infoJpeg[2], "\n";

$infoGif = null;
$gif2 = $dir . '/phpc_gd_info_' . $pid . '.gif';
imagegif($im, $gif2);
$infoGif = getimagesize($gif2);
@unlink($gif2);
echo 'getimagesize_gif=', (int) $infoGif[0], 'x', (int) $infoGif[1], ' type=', (int) $infoGif[2], "\n";
?>
--EXPECT--
imagecreatefrompng=1
imagecreatefromjpeg=1
imagecreatefromgif=1
imagejpeg=1
imagegif=1
imagepng=1
png_write=1
jpeg_write=1
gif_write=1
png_type=GdImage sx=3 sy=2
png_c00=16711680 c10=65280
jpeg_type=GdImage sx=3 sy=2
jpeg_c00=16711680 c10=65280
gif_type=GdImage sx=3 sy=2
gif_c00=16711680 c10=65280
getimagesize_jpeg=3x2 type=2
getimagesize_gif=3x2 type=1
