<?php
/**
 * #20472 — WBMP/XBM encode+decode + XPM soft-fail registration.
 */
foreach (['imagewbmp', 'imagecreatefromwbmp', 'imagexbm', 'imagecreatefromxbm', 'imagecreatefromxpm'] as $n) {
    echo $n, '=', function_exists($n) ? 'Y' : 'N', "\n";
}

$im = imagecreatetruecolor(2, 2);
$black = imagecolorallocate($im, 0, 0, 0);
$white = imagecolorallocate($im, 255, 255, 255);
imagefilledrectangle($im, 0, 0, 1, 1, $white);
imagesetpixel($im, 0, 0, $black);

$dir = sys_get_temp_dir() . '/phpc_gd_wbmp_' . bin2hex(random_bytes(3));
mkdir($dir, 0777, true);
$wbmp = $dir . '/t.wbmp';
$xbm = $dir . '/t.xbm';

echo 'wbmp_write=', imagewbmp($im, $wbmp) ? 'Y' : 'N', "\n";
$im2 = imagecreatefromwbmp($wbmp);
echo 'wbmp_read=', ($im2 !== false) ? 'Y' : 'N', "\n";
if ($im2 !== false) {
    echo 'wbmp_size=', imagesx($im2), 'x', imagesy($im2), "\n";
    // black pixel → near 0; white → near 0xFFFFFF
    $c00 = imagecolorat($im2, 0, 0) & 0xFFFFFF;
    $c10 = imagecolorat($im2, 1, 0) & 0xFFFFFF;
    echo 'wbmp_px=', ($c00 < 0x808080 ? 'B' : 'W'), ($c10 < 0x808080 ? 'B' : 'W'), "\n";
}

echo 'xbm_write=', imagexbm($im, $xbm) ? 'Y' : 'N', "\n";
$im3 = imagecreatefromxbm($xbm);
echo 'xbm_read=', ($im3 !== false) ? 'Y' : 'N', "\n";
if ($im3 !== false) {
    echo 'xbm_size=', imagesx($im3), 'x', imagesy($im3), "\n";
    $c00 = imagecolorat($im3, 0, 0) & 0xFFFFFF;
    $c10 = imagecolorat($im3, 1, 0) & 0xFFFFFF;
    echo 'xbm_px=', ($c00 < 0x808080 ? 'B' : 'W'), ($c10 < 0x808080 ? 'B' : 'W'), "\n";
}

@$xpm = imagecreatefromxpm($dir . '/missing.xpm');
echo 'xpm=', ($xpm === false) ? 'false' : 'ok', "\n";

$mask = imagetypes();
echo 'wbmp_bit=', (0 !== ($mask & IMG_WBMP)) ? 'Y' : 'N', "\n";
$info = gd_info();
echo 'wbmp_info=', !empty($info['WBMP Support']) ? 'Y' : 'N', "\n";
echo 'xbm_info=', !empty($info['XBM Support']) ? 'Y' : 'N', "\n";
echo 'xpm_info=', !empty($info['XPM Support']) ? 'Y' : 'N', "\n";

@unlink($wbmp);
@unlink($xbm);
@rmdir($dir);
