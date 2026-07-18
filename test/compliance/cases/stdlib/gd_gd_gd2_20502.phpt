--TEST--
stdlib gd imagegd/imagegd2 + createfromgd/gd2/gd2part (#20502, ext/gd/gd.c)
--FILE--
<?php
foreach (['imagegd', 'imagegd2', 'imagecreatefromgd', 'imagecreatefromgd2', 'imagecreatefromgd2part'] as $n) {
    echo $n, '=', (int) function_exists($n), "\n";
}
echo 'IMG_GD2_RAW=', (int) (defined('IMG_GD2_RAW') && IMG_GD2_RAW === 1), "\n";
echo 'IMG_GD2_COMPRESSED=', (int) (defined('IMG_GD2_COMPRESSED') && IMG_GD2_COMPRESSED === 2), "\n";

$im = imagecreatetruecolor(2, 2);
$r = imagecolorallocate($im, 255, 0, 0);
$g = imagecolorallocate($im, 0, 255, 0);
$b = imagecolorallocate($im, 0, 0, 255);
$w = imagecolorallocate($im, 255, 255, 255);
imagesetpixel($im, 0, 0, $r);
imagesetpixel($im, 1, 0, $g);
imagesetpixel($im, 0, 1, $b);
imagesetpixel($im, 1, 1, $w);

$dir = sys_get_temp_dir() . '/phpc_gd_gd_' . getmypid();
@mkdir($dir, 0777, true);
$gd = $dir . '/t.gd';
$gd2raw = $dir . '/t_raw.gd2';
$gd2comp = $dir . '/t_comp.gd2';

echo 'gd_write=', (int) imagegd($im, $gd), "\n";
$im2 = imagecreatefromgd($gd);
echo 'gd_read=', (int) ($im2 !== false), "\n";
echo 'gd_size=', imagesx($im2), 'x', imagesy($im2), "\n";
echo 'gd_tc=', (int) imageistruecolor($im2), "\n";
$c00 = imagecolorat($im2, 0, 0) & 0xFFFFFF;
$c10 = imagecolorat($im2, 1, 0) & 0xFFFFFF;
$c01 = imagecolorat($im2, 0, 1) & 0xFFFFFF;
$c11 = imagecolorat($im2, 1, 1) & 0xFFFFFF;
echo 'gd_px=', dechex($c00), ',', dechex($c10), ',', dechex($c01), ',', dechex($c11), "\n";

echo 'gd2raw_write=', (int) imagegd2($im, $gd2raw, 64, IMG_GD2_RAW), "\n";
$im3 = imagecreatefromgd2($gd2raw);
echo 'gd2raw_read=', (int) ($im3 !== false), "\n";
echo 'gd2raw_size=', imagesx($im3), 'x', imagesy($im3), "\n";
$c00 = imagecolorat($im3, 0, 0) & 0xFFFFFF;
$c11 = imagecolorat($im3, 1, 1) & 0xFFFFFF;
echo 'gd2raw_px=', dechex($c00), ',', dechex($c11), "\n";

echo 'gd2comp_write=', (int) imagegd2($im, $gd2comp, 64, IMG_GD2_COMPRESSED), "\n";
$im4 = imagecreatefromgd2($gd2comp);
echo 'gd2comp_read=', (int) ($im4 !== false), "\n";
echo 'gd2comp_size=', imagesx($im4), 'x', imagesy($im4), "\n";
$c00 = imagecolorat($im4, 0, 0) & 0xFFFFFF;
$c11 = imagecolorat($im4, 1, 1) & 0xFFFFFF;
echo 'gd2comp_px=', dechex($c00), ',', dechex($c11), "\n";

$part = imagecreatefromgd2part($gd2raw, 1, 0, 1, 2);
echo 'gd2part_read=', (int) ($part !== false), "\n";
echo 'gd2part_size=', imagesx($part), 'x', imagesy($part), "\n";
$p00 = imagecolorat($part, 0, 0) & 0xFFFFFF;
$p01 = imagecolorat($part, 0, 1) & 0xFFFFFF;
echo 'gd2part_px=', dechex($p00), ',', dechex($p01), "\n";

@unlink($gd);
@unlink($gd2raw);
@unlink($gd2comp);
@rmdir($dir);
echo 'ok', "\n";
?>
--EXPECT--
imagegd=1
imagegd2=1
imagecreatefromgd=1
imagecreatefromgd2=1
imagecreatefromgd2part=1
IMG_GD2_RAW=1
IMG_GD2_COMPRESSED=1
gd_write=1
gd_read=1
gd_size=2x2
gd_tc=1
gd_px=ff0000,ff00,ff,ffffff
gd2raw_write=1
gd2raw_read=1
gd2raw_size=2x2
gd2raw_px=ff0000,ffffff
gd2comp_write=1
gd2comp_read=1
gd2comp_size=2x2
gd2comp_px=ff0000,ffffff
gd2part_read=1
gd2part_size=1x2
gd2part_px=ff00,ffffff
ok
