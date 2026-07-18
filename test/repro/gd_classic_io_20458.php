<?php
foreach (['imagecreatefrompng', 'imagecreatefromjpeg', 'imagecreatefromgif', 'imagejpeg', 'imagegif', 'imagepng'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', PHP_EOL;
}
$im = imagecreatetruecolor(3, 2);
$r = imagecolorallocate($im, 255, 0, 0);
$g = imagecolorallocate($im, 0, 255, 0);
imagefill($im, 0, 0, $r);
imagesetpixel($im, 1, 0, $g);
$base = sys_get_temp_dir() . '/t' . getmypid();
$pngPath = $base . '.png';
$jpgPath = $base . '.jpg';
$gifPath = $base . '.gif';
imagepng($im, $pngPath);
imagejpeg($im, $jpgPath, 90);
imagegif($im, $gifPath);
$a = imagecreatefrompng($pngPath);
$b = imagecreatefromjpeg($jpgPath);
$c = imagecreatefromgif($gifPath);
echo 'png ', imagesx($a), 'x', imagesy($a), ' ', (imagecolorat($a, 0, 0) & 0xffffff), ' ', (imagecolorat($a, 1, 0) & 0xffffff), PHP_EOL;
echo 'jpg ', imagesx($b), 'x', imagesy($b), ' ', (imagecolorat($b, 0, 0) & 0xffffff), ' ', (imagecolorat($b, 1, 0) & 0xffffff), PHP_EOL;
echo 'gif ', imagesx($c), 'x', imagesy($c), ' ', (imagecolorat($c, 0, 0) & 0xffffff), ' ', (imagecolorat($c, 1, 0) & 0xffffff), PHP_EOL;
$info = getimagesize($jpgPath);
echo 'infojpg ', $info[0], 'x', $info[1], ' t', $info[2], PHP_EOL;
$info = getimagesize($gifPath);
echo 'infogif ', $info[0], 'x', $info[1], ' t', $info[2], PHP_EOL;
@unlink($pngPath);
@unlink($jpgPath);
@unlink($gifPath);
