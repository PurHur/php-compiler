--TEST--
stdlib gd imagebmp/imagecreatefrombmp round-trip (#20417, ext/gd/gd.c)
--FILE--
<?php
foreach (['imagecreatefrombmp', 'imagebmp'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}
$im = imagecreatetruecolor(3, 2);
$red = imagecolorallocate($im, 255, 0, 0);
$green = imagecolorallocate($im, 0, 255, 0);
imagefill($im, 0, 0, $red);
imagesetpixel($im, 1, 0, $green);
$tmp = sys_get_temp_dir() . '/phpc_gd_bmp_' . getmypid() . '.bmp';
echo 'write=', (int) imagebmp($im, $tmp), "\n";
$loaded = imagecreatefrombmp($tmp);
@unlink($tmp);
echo 'type=', get_debug_type($loaded), "\n";
echo 'sx=', imagesx($loaded), ' sy=', imagesy($loaded), "\n";
echo 'c00=', imagecolorat($loaded, 0, 0) & 0xFFFFFF, "\n";
echo 'c10=', imagecolorat($loaded, 1, 0) & 0xFFFFFF, "\n";
?>
--EXPECT--
imagecreatefrombmp=1
imagebmp=1
write=1
type=GdImage
sx=3 sy=2
c00=16711680
c10=65280
