--TEST--
stdlib gd imagecreatefromtga + gd_info/imagetypes TGA bits (#20503, ext/gd/gd.c)
--FILE--
<?php
echo 'exists=', (int) function_exists('imagecreatefromtga'), "\n";
echo 'IMG_TGA=', (int) (defined('IMG_TGA') && IMG_TGA === 128), "\n";
$mask = imagetypes();
echo 'tga_bit=', (int) (0 !== ($mask & IMG_TGA)), "\n";
$info = gd_info();
echo 'tga_info=', (int) (!empty($info['TGA Read Support'])), "\n";

// Uncompressed 24-bpp top-left 2x2: R,G / B,W
$pixels = [0xFF0000, 0x00FF00, 0x0000FF, 0xFFFFFF];
$bytes = '';
$bytes .= chr(0).chr(0).chr(2); // RGB
$bytes .= pack('v', 0).pack('v', 0).chr(0);
$bytes .= pack('v', 0).pack('v', 0);
$bytes .= pack('v', 2).pack('v', 2);
$bytes .= chr(24).chr(0x20);
foreach ($pixels as $c) {
    $bytes .= chr($c & 0xFF).chr(($c >> 8) & 0xFF).chr(($c >> 16) & 0xFF);
}
$dir = sys_get_temp_dir() . '/phpc_tga_' . getmypid();
@mkdir($dir, 0777, true);
$path = $dir . '/t.tga';
file_put_contents($path, $bytes);

$im = imagecreatefromtga($path);
echo 'read=', (int) ($im !== false), "\n";
echo 'size=', imagesx($im), 'x', imagesy($im), "\n";
echo 'tc=', (int) imageistruecolor($im), "\n";
$c00 = imagecolorat($im, 0, 0) & 0xFFFFFF;
$c10 = imagecolorat($im, 1, 0) & 0xFFFFFF;
$c01 = imagecolorat($im, 0, 1) & 0xFFFFFF;
$c11 = imagecolorat($im, 1, 1) & 0xFFFFFF;
echo 'px=', dechex($c00), ',', dechex($c10), ',', dechex($c01), ',', dechex($c11), "\n";

@unlink($path);
@rmdir($dir);
echo 'ok', "\n";
?>
--EXPECT--
exists=1
IMG_TGA=1
tga_bit=1
tga_info=1
read=1
size=2x2
tc=1
px=ff0000,ff00,ff,ffffff
ok
