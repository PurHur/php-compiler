--TEST--
stdlib gd WBMP/XBM I/O + XPM soft-fail (#20472, ext/gd/gd.c)
--FILE--
<?php
foreach (['imagewbmp', 'imagecreatefromwbmp', 'imagexbm', 'imagecreatefromxbm', 'imagecreatefromxpm'] as $n) {
    echo $n, '=', (int) function_exists($n), "\n";
}

$im = imagecreatetruecolor(2, 2);
$black = imagecolorallocate($im, 0, 0, 0);
$white = imagecolorallocate($im, 255, 255, 255);
imagefilledrectangle($im, 0, 0, 1, 1, $white);
imagesetpixel($im, 0, 0, $black);

$dir = sys_get_temp_dir() . '/phpc_gd_wbmp_' . getmypid();
@mkdir($dir, 0777, true);
$wbmp = $dir . '/t.wbmp';
$xbm = $dir . '/t.xbm';

echo 'wbmp_write=', (int) imagewbmp($im, $wbmp), "\n";
$im2 = imagecreatefromwbmp($wbmp);
echo 'wbmp_read=', (int) ($im2 !== false), "\n";
echo 'wbmp_size=', imagesx($im2), 'x', imagesy($im2), "\n";
$c00 = imagecolorat($im2, 0, 0) & 0xFFFFFF;
$c10 = imagecolorat($im2, 1, 0) & 0xFFFFFF;
echo 'wbmp_px=', ($c00 < 0x808080 ? 'B' : 'W'), ($c10 < 0x808080 ? 'B' : 'W'), "\n";

echo 'xbm_write=', (int) imagexbm($im, $xbm), "\n";
$im3 = imagecreatefromxbm($xbm);
echo 'xbm_read=', (int) ($im3 !== false), "\n";
echo 'xbm_size=', imagesx($im3), 'x', imagesy($im3), "\n";
$c00 = imagecolorat($im3, 0, 0) & 0xFFFFFF;
$c10 = imagecolorat($im3, 1, 0) & 0xFFFFFF;
echo 'xbm_px=', ($c00 < 0x808080 ? 'B' : 'W'), ($c10 < 0x808080 ? 'B' : 'W'), "\n";

@$xpm = imagecreatefromxpm($dir . '/missing.xpm');
echo 'xpm_false=', (int) ($xpm === false), "\n";

$mask = imagetypes();
echo 'wbmp_bit=', (int) (0 !== ($mask & IMG_WBMP)), "\n";
$info = gd_info();
echo 'wbmp_info=', (int) (!empty($info['WBMP Support'])), "\n";
echo 'xbm_info=', (int) (!empty($info['XBM Support'])), "\n";
echo 'xpm_info=', (int) (!empty($info['XPM Support'])), "\n";

@unlink($wbmp);
@unlink($xbm);
@rmdir($dir);
echo 'ok', "\n";
?>
--EXPECT--
imagewbmp=1
imagecreatefromwbmp=1
imagexbm=1
imagecreatefromxbm=1
imagecreatefromxpm=1
wbmp_write=1
wbmp_read=1
wbmp_size=2x2
wbmp_px=BW
xbm_write=1
xbm_read=1
xbm_size=2x2
xbm_px=BW
xpm_false=1
wbmp_bit=1
wbmp_info=1
xbm_info=1
xpm_info=0
ok
